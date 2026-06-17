<?php

namespace App\Jobs;

use App\Models\MigrationLog;
use App\Models\MigrationRun;
use App\Services\CancellationService;
use App\Services\RedirectionClient;
use App\Services\ShopwareDB;
use App\Services\StateManager;
use App\Shopware\Readers\SeoUrlReader;
use App\Shopware\Transformers\SeoUrlTransformer;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * One chunk of seo_url rows. The dispatcher (MigrateSeoUrlsJob) selects the
 * deduped ID set up-front and slices it into ~500-row chunks; each chunk runs
 * here as an independent, retry-safe job. Per-chunk runtime is seconds — well
 * under any worker timeout — so a SIGTERM mid-iteration only loses one batch's
 * worth of work, which automatically resumes via alreadyMigrated().
 */
class MigrateSeoUrlBatchJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 5;

    public int $timeout = 600;

    /**
     * @param  array<int, string>  $seoUrlIds  lowercase-hex seo_url ids assigned to this chunk
     * @param  array<string, true>  $existingSources  set of already-present Redirection sources
     */
    public function __construct(
        protected int $migrationId,
        protected array $seoUrlIds,
        protected ?int $groupId = null,
        protected array $existingSources = [],
    ) {
        $this->onQueue('seo');
    }

    public function handle(StateManager $stateManager, CancellationService $cancellation): void
    {
        if ($this->seoUrlIds === []) {
            return;
        }

        $migration = MigrationRun::findOrFail($this->migrationId);

        if ($cancellation->isCancelled($this->migrationId)) {
            $this->batch()?->cancel();

            return;
        }

        $apiEnabled = (bool) config('migration.redirection.enabled', true);
        $defaultCode = (int) config('migration.redirection.default_code', 301);

        $reader = $this->makeReader($migration);
        $transformer = $this->makeTransformer();

        // The dispatcher pre-resolves the Redirection plugin's group id + existing
        // source set once and passes them into each batch job so workers don't
        // each hammer the plugin's API.
        $usingApi = $apiEnabled && ! $migration->is_dry_run && $this->groupId !== null;
        $client = $usingApi ? $this->makeClient($migration) : null;

        $rows = $reader->fetchByIds($this->seoUrlIds);
        $csv = $this->openCsv();

        try {
            foreach ($rows as $row) {
                if ($cancellation->isCancelled($this->migrationId)) {
                    $this->batch()?->cancel();

                    return;
                }

                if ($stateManager->alreadyMigrated('seo_url', $row->id, $this->migrationId)) {
                    continue;
                }

                $this->processRow(
                    $row,
                    $stateManager,
                    $transformer,
                    $client,
                    $csv,
                    $migration,
                    $this->existingSources,
                    $defaultCode,
                    $this->groupId,
                    $apiEnabled,
                    $usingApi,
                );
            }
        } finally {
            fclose($csv);
        }
    }

    /**
     * Mark every row in this chunk as failed when the worker exhausts retries.
     */
    public function failed(Throwable $exception): void
    {
        $stateManager = app(StateManager::class);

        foreach ($this->seoUrlIds as $id) {
            $stateManager->markFailed('seo_url', $id, $this->migrationId, $exception->getMessage());
        }

        MigrationLog::create([
            'migration_id' => $this->migrationId,
            'entity_type' => 'seo_url',
            'level' => 'error',
            'message' => 'SEO URL batch failed after retries: '.$exception->getMessage(),
            'created_at' => now(),
        ]);
    }

    /**
     * @param  resource  $csv
     */
    protected function processRow(
        object $row,
        StateManager $stateManager,
        SeoUrlTransformer $transformer,
        ?RedirectionClient $client,
        $csv,
        MigrationRun $migration,
        array $existingSources,
        int $defaultCode,
        ?int $groupId,
        bool $apiEnabled,
        bool $usingApi,
    ): void {
        $entityType = $this->resolveEntityType($row->route_name);

        $entity = $stateManager->getEntity($entityType, $row->foreign_key, $this->migrationId);

        if ($entityType === 'product' && $entity === null) {
            $variation = $stateManager->getEntity('variation', $row->foreign_key, $this->migrationId);
            $parentShopwareId = $variation?->payload['parent_shopware_id'] ?? null;
            if ($parentShopwareId) {
                $entity = $stateManager->getEntity('product', $parentShopwareId, $this->migrationId);
            }
        }

        $wooId = $entity?->woo_id;
        $slug = $entity?->payload['slug'] ?? null;

        $okForReal = $entity && $entity->status === 'success' && $wooId !== null;
        $okForDryRun = false;
        if ($migration->is_dry_run && $entity && $entity->status === 'skipped') {
            if (($slug === null || $slug === '') && ! empty($entity->payload['name'])) {
                $slug = \Illuminate\Support\Str::slug((string) $entity->payload['name']);
            }
            $okForDryRun = $slug !== null && $slug !== '';
        }

        if (! $okForReal && ! $okForDryRun) {
            $orphan = $entity === null;
            $reason = $orphan
                ? "references {$entityType} {$row->foreign_key} which does not exist in Shopware (likely deleted; stale seo_url row)"
                : "references {$entityType} {$row->foreign_key} which is not yet migrated; leaving for next pass";
            $level = 'info';

            $this->log($level, "SEO URL '/{$row->seo_path_info}' {$reason}", $row->id);

            if ($orphan) {
                $stateManager->markSkipped(
                    'seo_url',
                    $row->id,
                    $this->migrationId,
                    ['skip_reason' => 'orphaned_reference', 'source' => '/'.$row->seo_path_info, 'foreign_key' => $row->foreign_key]
                );
            }

            return;
        }

        try {
            $data = $transformer->transform($row, $entityType, $wooId, $slug);
        } catch (\InvalidArgumentException $e) {
            $stateManager->markFailed('seo_url', $row->id, $this->migrationId, $e->getMessage());
            $this->log('error', $e->getMessage(), $row->id);

            return;
        }

        if ($data['target'] === '') {
            $stateManager->markSkipped('seo_url', $row->id, $this->migrationId, $this->skipPayload($data, 'no_target'));

            return;
        }

        if ($data['is_self_redirect']) {
            $stateManager->markSkipped('seo_url', $row->id, $this->migrationId, $this->skipPayload($data, 'self_redirect'));
            $this->log('info', "Self-redirect skipped: '{$data['source']}'", $row->id);

            return;
        }

        if (isset($existingSources[$data['source']])) {
            $stateManager->markSkipped('seo_url', $row->id, $this->migrationId, $this->skipPayload($data, 'exists_in_redirection'));

            return;
        }

        // flock-protected CSV append so parallel batch workers don't interleave
        // (currently maxProcesses=1 on supervisor-seo, but defensive for the future).
        if (flock($csv, LOCK_EX)) {
            fputcsv($csv, [$data['source'], $data['target'], '', (string) $data['code']]);
            fflush($csv);
            flock($csv, LOCK_UN);
        }

        $this->emitCanonicalDetailRedirect($row, $entityType, $data, $csv, $client, $existingSources, $groupId, $defaultCode, $usingApi);

        if ($migration->is_dry_run) {
            $stateManager->markSkipped('seo_url', $row->id, $this->migrationId, $this->skipPayload($data, 'dry_run'));

            return;
        }

        if (! $apiEnabled) {
            $stateManager->markSkipped('seo_url', $row->id, $this->migrationId, $this->skipPayload($data, 'api_disabled'));

            return;
        }

        if (! $usingApi || $client === null || $groupId === null) {
            $stateManager->markSkipped('seo_url', $row->id, $this->migrationId, $this->skipPayload($data, 'plugin_unavailable'));

            return;
        }

        try {
            $ruleId = $client->createRedirect(
                $data['source'],
                $data['target'],
                $data['code'] ?: $defaultCode,
                $groupId,
            );
            $stateManager->set('seo_url', $row->id, $ruleId, $this->migrationId, $data);
            $this->log('info', "Migrated SEO URL '{$data['source']}' → '{$data['target']}' (rule #{$ruleId})", $row->id);
        } catch (Throwable $e) {
            $stateManager->markFailed('seo_url', $row->id, $this->migrationId, $e->getMessage());
            $this->log('error', "Failed to push redirect: {$e->getMessage()}", $row->id);
        }
    }

    /**
     * Shopware serves a canonical, non-pretty URL for every product alongside its
     * SEO slug: /detail/{productId} (stored verbatim in seo_url.path_info). Those
     * canonical links are indexed and bookmarked, so emit a redirect for them too,
     * pointing at the same WooCommerce target as the pretty URL.
     *
     * Only the canonical seo_url row per product carries this (is_canonical) so we
     * emit exactly once per product/variant — non-canonical aliases share the same
     * path_info and would otherwise produce duplicate rules. Products only; category
     * /navigation/{id} paths were never publicly linked.
     *
     * @param  resource  $csv
     * @param  array{source: string, target: string, code: int, is_self_redirect: bool, metadata: array<string, mixed>}  $data
     * @param  array<string, true>  $existingSources
     */
    protected function emitCanonicalDetailRedirect(
        object $row,
        string $entityType,
        array $data,
        $csv,
        ?RedirectionClient $client,
        array $existingSources,
        ?int $groupId,
        int $defaultCode,
        bool $usingApi,
    ): void {
        if ($entityType !== 'product' || empty($row->is_canonical)) {
            return;
        }

        $source = (string) ($row->path_info ?? '');
        if (! str_starts_with($source, '/detail/')) {
            return;
        }

        if ($source === $data['target'] || isset($existingSources[$source])) {
            return;
        }

        if (flock($csv, LOCK_EX)) {
            fputcsv($csv, [$source, $data['target'], '', (string) $data['code']]);
            fflush($csv);
            flock($csv, LOCK_UN);
        }

        if (! $usingApi || $client === null || $groupId === null) {
            return;
        }

        try {
            $client->createRedirect($source, $data['target'], $data['code'] ?: $defaultCode, $groupId);
        } catch (Throwable $e) {
            $this->log('warning', "Failed to push canonical /detail redirect '{$source}': {$e->getMessage()}", $row->id);
        }
    }

    protected function resolveEntityType(string $routeName): string
    {
        if ($routeName === 'frontend.detail.page') {
            return 'product';
        }
        if ($routeName === 'frontend.navigation.page') {
            return 'category';
        }
        if (str_starts_with($routeName, 'frontend.cms.page')) {
            return 'cms_page';
        }

        return 'product';
    }

    protected function makeReader(MigrationRun $migration): SeoUrlReader
    {
        $db = ShopwareDB::fromMigration($migration);

        return app(SeoUrlReader::class, ['db' => $db]);
    }

    protected function makeTransformer(): SeoUrlTransformer
    {
        return app(SeoUrlTransformer::class);
    }

    protected function makeClient(MigrationRun $migration): RedirectionClient
    {
        $woo = $migration->woocommerceSettings();
        $wp = $migration->wordpressSettings();

        $config = [
            'base_url' => $woo['base_url'] ?? '',
            'wp_username' => $wp['username'] ?? '',
            'wp_app_password' => $wp['app_password'] ?? '',
            'custom_headers' => $wp['custom_headers'] ?? [],
        ];

        return app(RedirectionClient::class, ['config' => $config]);
    }

    /**
     * @return resource
     */
    protected function openCsv()
    {
        $dir = storage_path("app/migrations/{$this->migrationId}");
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $path = $dir.'/redirects.csv';

        $handle = fopen($path, 'a');
        if ($handle === false) {
            throw new \RuntimeException("Unable to open CSV at {$path}");
        }

        return $handle;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function skipPayload(array $data, string $reason): array
    {
        return $data + ['skip_reason' => $reason];
    }

    protected function log(string $level, string $message, ?string $shopwareId = null): void
    {
        MigrationLog::create([
            'migration_id' => $this->migrationId,
            'entity_type' => 'seo_url',
            'shopware_id' => $shopwareId,
            'level' => $level,
            'message' => $message,
            'created_at' => now(),
        ]);
    }
}

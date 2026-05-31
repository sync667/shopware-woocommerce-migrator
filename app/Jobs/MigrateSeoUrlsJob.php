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
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class MigrateSeoUrlsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 5;

    public int $timeout = 3600;

    public function __construct(protected int $migrationId) {}

    public function handle(StateManager $stateManager, CancellationService $cancellation): void
    {
        $migration = MigrationRun::findOrFail($this->migrationId);

        $reader = $this->makeReader($migration);
        $transformer = $this->makeTransformer();
        $client = $this->makeClient($migration);

        $groupName = (string) config('migration.redirection.group_name', 'Shopware Migration');
        $defaultCode = (int) config('migration.redirection.default_code', 301);
        $apiEnabled = (bool) config('migration.redirection.enabled', true);

        $csv = $this->openCsv();

        $usingApi = false;
        $groupId = null;
        $existingSources = [];
        if ($apiEnabled) {
            if ($client->isAvailable()) {
                $usingApi = true;
                $groupId = $client->ensureGroup($groupName);
                $existingSources = array_fill_keys($client->loadExistingSources($groupId), true);
            } else {
                $this->log('warning', 'Redirection plugin not reachable; running in file-only mode');
            }
        }

        $createdSources = [];

        $streams = [
            ['product', fn () => $reader->fetchAllForProducts()],
            ['category', fn () => $reader->fetchAllForCategories()],
            ['cms_page', fn () => $reader->fetchAllForCmsPages()],
        ];

        try {
            foreach ($streams as [$entityType, $fetcher]) {
                $rows = $fetcher();

                foreach ($rows as $row) {
                    if ($cancellation->isCancelled($this->migrationId)) {
                        return;
                    }

                    if ($stateManager->alreadyMigrated('seo_url', $row->id, $this->migrationId)) {
                        continue;
                    }

                    $entity = $stateManager->getEntity($entityType, $row->foreign_key, $this->migrationId);

                    $wooId = $entity?->woo_id;
                    $slug = $entity?->payload['slug'] ?? null;

                    // The target entity must have actually been migrated. A pending/failed entity
                    // (with only a placeholder slug from an earlier pass) would produce a redirect
                    // to a URL that 404s, so leave the seo_url row untouched for the next pass.
                    if ($entity === null || $entity->status !== 'success' || $wooId === null) {
                        $this->log(
                            'warning',
                            "SEO URL '/{$row->seo_path_info}' references {$entityType} {$row->foreign_key} which is not yet migrated; leaving for next pass",
                            $row->id,
                            'seo_url'
                        );

                        continue;
                    }

                    try {
                        $data = $transformer->transform($row, $entityType, $wooId, $slug);
                    } catch (\InvalidArgumentException $e) {
                        $stateManager->markFailed('seo_url', $row->id, $this->migrationId, $e->getMessage());
                        $this->log('error', $e->getMessage(), $row->id, 'seo_url');

                        continue;
                    }

                    if ($data['target'] === '') {
                        $stateManager->markSkipped(
                            'seo_url',
                            $row->id,
                            $this->migrationId,
                            $this->skipPayload($data, 'no_target')
                        );

                        continue;
                    }

                    if ($data['is_self_redirect']) {
                        $stateManager->markSkipped(
                            'seo_url',
                            $row->id,
                            $this->migrationId,
                            $this->skipPayload($data, 'self_redirect')
                        );
                        $this->log('info', "Self-redirect skipped: '{$data['source']}'", $row->id, 'seo_url');

                        continue;
                    }

                    if (isset($createdSources[$data['source']])) {
                        $stateManager->markSkipped(
                            'seo_url',
                            $row->id,
                            $this->migrationId,
                            $this->skipPayload($data, 'source_collision')
                        );
                        $this->log(
                            'warning',
                            "Source '{$data['source']}' collides with another SEO URL in this run; first one wins",
                            $row->id,
                            'seo_url'
                        );

                        continue;
                    }

                    if (isset($existingSources[$data['source']])) {
                        $stateManager->markSkipped(
                            'seo_url',
                            $row->id,
                            $this->migrationId,
                            $this->skipPayload($data, 'exists_in_redirection')
                        );

                        continue;
                    }

                    // CSV captures the intended redirect for every mode that would have created one.
                    fputcsv($csv, [$data['source'], $data['target'], '', (string) $data['code']]);

                    if ($migration->is_dry_run) {
                        $stateManager->markSkipped(
                            'seo_url',
                            $row->id,
                            $this->migrationId,
                            $this->skipPayload($data, 'dry_run')
                        );

                        continue;
                    }

                    if (! $apiEnabled) {
                        $stateManager->markSkipped(
                            'seo_url',
                            $row->id,
                            $this->migrationId,
                            $this->skipPayload($data, 'api_disabled')
                        );

                        continue;
                    }

                    if (! $usingApi) {
                        $stateManager->markSkipped(
                            'seo_url',
                            $row->id,
                            $this->migrationId,
                            $this->skipPayload($data, 'plugin_unavailable')
                        );

                        continue;
                    }

                    try {
                        $ruleId = $client->createRedirect(
                            $data['source'],
                            $data['target'],
                            $data['code'] ?: $defaultCode,
                            $groupId,
                        );
                        $createdSources[$data['source']] = true;
                        $stateManager->set('seo_url', $row->id, $ruleId, $this->migrationId, $data);
                        $this->log('info', "Migrated SEO URL '{$data['source']}' → '{$data['target']}' (rule #{$ruleId})", $row->id, 'seo_url');
                    } catch (Throwable $e) {
                        $stateManager->markFailed('seo_url', $row->id, $this->migrationId, $e->getMessage());
                        $this->log('error', "Failed to push redirect: {$e->getMessage()}", $row->id, 'seo_url');
                    }
                }
            }
        } finally {
            fclose($csv);
        }
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

        $isNew = ! file_exists($path) || filesize($path) === 0;
        $handle = fopen($path, 'a');
        if ($handle === false) {
            throw new \RuntimeException("Unable to open CSV at {$path}");
        }

        if ($isNew) {
            // UTF-8 BOM so non-ASCII slugs (Polish/German/etc.) survive a round trip through
            // Excel or any importer that auto-detects encoding from the first bytes.
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['source', 'target', 'regex', 'code']);
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

    protected function log(string $level, string $message, ?string $shopwareId = null, ?string $entityType = null): void
    {
        MigrationLog::create([
            'migration_id' => $this->migrationId,
            'entity_type' => $entityType,
            'shopware_id' => $shopwareId,
            'level' => $level,
            'message' => $message,
            'created_at' => now(),
        ]);
    }
}

<?php

namespace App\Jobs;

use App\Models\MigrationLog;
use App\Models\MigrationRun;
use App\Services\CancellationService;
use App\Services\RedirectionClient;
use App\Services\ShopwareDB;
use App\Shopware\Readers\SeoUrlReader;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;

/**
 * Dispatcher for the SEO URL stage. Used to process all ~90k rows inline; now
 * fetches the deduped row-id list once, opens the CSV with a header, then fires
 * a Bus::batch of MigrateSeoUrlBatchJob instances of ~500 rows each. The batch's
 * then() callback advances the chain to the optional CMS / streams / newsletter
 * / wishlist jobs and finally the completion closure.
 *
 * Chunking means a worker SIGTERM only loses one chunk; the chain's
 * alreadyMigrated() check resumes from the next chunk on retry.
 */
class MigrateSeoUrlsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 5;

    /** Dispatcher itself is fast (one dedup query + chunk + batch dispatch). */
    public int $timeout = 600;

    public const CHUNK_SIZE = 500;

    public function __construct(protected int $migrationId)
    {
        $this->onQueue('seo');
    }

    public function handle(CancellationService $cancellation): void
    {
        $migration = MigrationRun::findOrFail($this->migrationId);

        if ($cancellation->isCancelled($this->migrationId)) {
            return;
        }

        $reader = $this->makeReader($migration);

        // Run the dedup query once up front. Returns ~40-90k (id, seo_path_info)
        // pairs depending on shop size — well under any memory limit.
        $allRows = $reader->fetchAllIds();

        // Cross-entity source collision: two DIFFERENT shopware entities can share
        // the same canonical seo_path_info (rare but real on shops where the
        // category tree was restructured). The first one wins; the rest get
        // marked 'skipped/source_collision' so the operator can see them in the
        // dashboard. SQL dedup handles same-entity duplicates per channel; this
        // handles cross-entity duplicates per path.
        $seen = [];
        $ids = [];
        $collisions = [];
        foreach ($allRows as $row) {
            $path = (string) $row->seo_path_info;
            if (isset($seen[$path])) {
                $collisions[] = $row->id;

                continue;
            }
            $seen[$path] = true;
            $ids[] = $row->id;
        }
        if ($collisions !== []) {
            $stateManager = app(\App\Services\StateManager::class);
            foreach ($collisions as $collisionId) {
                $stateManager->markSkipped(
                    'seo_url',
                    $collisionId,
                    $this->migrationId,
                    ['skip_reason' => 'source_collision']
                );
            }
            $this->log('warning', 'SEO URL dispatcher: marked '.count($collisions).' cross-entity source collision(s) as skipped.');
        }

        $this->log('info', 'SEO URL dispatcher: deduped to '.count($ids).' row(s) for processing.');

        // Initialize the CSV with the header + BOM. Batch workers append in 'a' mode
        // under flock, so they share this single file.
        $this->initCsvHeader();

        // Resolve the Redirection plugin once (not in every batch) for real runs.
        // The group id + existing source set ride along in the batch's options bag
        // so workers don't each hammer the plugin API.
        [$groupId, $existingSources] = $this->resolveRedirectionContext($migration);

        if ($ids === []) {
            $this->log('info', 'SEO URL dispatcher: no rows to process, advancing chain.');
            self::dispatchPostSeoChain($this->migrationId);

            return;
        }

        $chunks = array_chunk($ids, self::CHUNK_SIZE);
        $migrationId = $this->migrationId;
        $batchJobs = array_map(
            fn ($chunk) => new MigrateSeoUrlBatchJob($migrationId, $chunk, $groupId, $existingSources),
            $chunks,
        );

        $this->log('info', 'SEO URL dispatcher: dispatching '.count($chunks).' batch(es) of up to '.self::CHUNK_SIZE.' row(s).');

        Bus::batch($batchJobs)
            ->name("seo-urls-migration-{$migrationId}")
            ->allowFailures()
            ->then(function () use ($migrationId) {
                self::dispatchPostSeoChain($migrationId);
            })
            ->catch(function (\Illuminate\Bus\Batch $batch, \Throwable $e) use ($migrationId) {
                MigrationLog::create([
                    'migration_id' => $migrationId,
                    'entity_type' => 'seo_url',
                    'level' => 'error',
                    'message' => 'SEO URL batch error: '.$e->getMessage(),
                    'created_at' => now(),
                ]);
                // Still advance the chain so the migration doesn't sit in 'running'
                // forever — the per-batch failed() hook already marked the affected
                // row state.
                self::dispatchPostSeoChain($migrationId);
            })
            ->onQueue('seo')
            ->dispatch();
    }

    /**
     * Run the rest of the final chain after the SEO batch finishes. Previously
     * lived in MigrateReviewsJob::dispatchFinalChain right next to the SEO step;
     * moved here so the chain can wait on the asynchronous batch instead of
     * racing past it.
     */
    public static function dispatchPostSeoChain(int $migrationId): void
    {
        $migration = MigrationRun::findOrFail($migrationId);
        $jobs = [];

        $cmsOptions = $migration->settings['cms_options'] ?? [];
        if (! empty($cmsOptions['migrate_all'])) {
            $jobs[] = new MigrateCmsPagesJob($migrationId);
        } elseif (! empty($cmsOptions['selected_ids'])) {
            $jobs[] = new MigrateCmsPagesJob($migrationId, $cmsOptions['selected_ids']);
        }

        $streamOptions = $migration->settings['stream_options'] ?? [];
        if (! empty($streamOptions['migrate_streams'])) {
            $jobs[] = new MigrateProductStreamsJob($migrationId);
        }

        if (! empty($migration->settings['newsletter_options']['enabled'])) {
            $jobs[] = new MigrateNewsletterRecipientsJob($migrationId);
        }

        if (! empty($migration->settings['wishlist_options']['enabled'])) {
            $jobs[] = new MigrateCustomerWishlistsJob($migrationId);
        }

        $jobs[] = function () use ($migrationId) {
            $migration = MigrationRun::findOrFail($migrationId);
            if ($migration->status === 'running') {
                $migration->markCompleted();
            }
            app(CancellationService::class)->clear($migrationId);
        };

        Bus::chain($jobs)
            ->catch(function (\Throwable $e) use ($migrationId) {
                MigrationLog::create([
                    'migration_id' => $migrationId,
                    'entity_type' => 'system',
                    'level' => 'error',
                    'message' => 'Post-SEO chain aborted: '.$e->getMessage(),
                    'created_at' => now(),
                ]);
                $migration = MigrationRun::find($migrationId);
                if ($migration && $migration->status === 'running') {
                    $migration->markFailed();
                }
                app(CancellationService::class)->clear($migrationId);
            })
            ->dispatch();
    }

    /**
     * Resolve the Redirection plugin group id + existing source set in the
     * dispatcher, once, so all batch workers share the result. Dry runs and
     * unavailable plugins return (null, []) which the workers interpret as
     * "CSV-only mode".
     *
     * @return array{0: ?int, 1: array<string, true>}
     */
    protected function resolveRedirectionContext(MigrationRun $migration): array
    {
        if ($migration->is_dry_run) {
            return [null, []];
        }

        if (! (bool) config('migration.redirection.enabled', true)) {
            return [null, []];
        }

        $client = $this->makeClient($migration);

        try {
            if (! $client->isAvailable()) {
                $this->log('warning', 'Redirection plugin not reachable; running in file-only mode');

                return [null, []];
            }

            $groupName = (string) config('migration.redirection.group_name', 'Shopware Migration');
            $groupId = $client->ensureGroup($groupName);
            $existingSources = array_fill_keys($client->loadExistingSources($groupId), true);

            return [$groupId, $existingSources];
        } catch (\Throwable $e) {
            $this->log('warning', "Redirection plugin error — falling back to CSV-only mode: {$e->getMessage()}");

            return [null, []];
        }
    }

    protected function initCsvHeader(): void
    {
        $dir = storage_path("app/migrations/{$this->migrationId}");
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $path = $dir.'/redirects.csv';

        if (file_exists($path) && filesize($path) > 0) {
            return;
        }

        $handle = fopen($path, 'w');
        if ($handle === false) {
            throw new \RuntimeException("Unable to open CSV at {$path}");
        }
        // UTF-8 BOM so non-ASCII slugs survive a round trip through Excel.
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, ['source', 'target', 'regex', 'code']);
        fclose($handle);
    }

    protected function makeReader(MigrationRun $migration): SeoUrlReader
    {
        $db = ShopwareDB::fromMigration($migration);

        return app(SeoUrlReader::class, ['db' => $db]);
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

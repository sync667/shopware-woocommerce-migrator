<?php

namespace App\Jobs;

use App\Models\MigrationEntity;
use App\Models\MigrationLog;
use App\Models\MigrationRun;
use App\Services\ShopwareDB;
use App\Services\WooCommerceClient;
use App\Shopware\Readers\ProductReader;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class LinkCrossSellBatchJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 5;

    public int $timeout = 1200;

    /** @param  array<int, string>  $shopwareProductIds */
    public function __construct(
        protected int $migrationId,
        protected array $shopwareProductIds,
    ) {
        $this->onQueue('migration');
    }

    public function handle(): void
    {
        $migration = MigrationRun::findOrFail($this->migrationId);

        if ($this->batch()?->cancelled() || app(\App\Services\CancellationService::class)->isCancelled($this->migrationId)) {
            return;
        }

        $sw = $migration->shopwareSettings();
        $upsellGroups = array_map('mb_strtolower', $sw['upsell_group_names'] ?? []);

        $migratedProducts = MigrationEntity::where('migration_id', $this->migrationId)
            ->where('entity_type', 'product')
            ->whereIn('status', ['success', 'skipped'])
            ->pluck('woo_id', 'shopware_id')
            ->filter()
            ->all();

        $db = ShopwareDB::fromMigration($migration);
        $reader = new ProductReader($db);
        $woo = $migration->is_dry_run ? null : WooCommerceClient::fromMigration($migration);

        $linked = $skipped = $failed = 0;
        $unresolved = 0;

        try {
            foreach ($this->shopwareProductIds as $shopwareId) {
                if ($this->batch()?->cancelled() || app(\App\Services\CancellationService::class)->isCancelled($this->migrationId)) {
                    return;
                }

                $wooProductId = $migratedProducts[$shopwareId] ?? null;
                if (! $wooProductId) {
                    continue;
                }

                $rows = $reader->fetchCrossSells($shopwareId);
                if ($rows === []) {
                    continue;
                }

                [$upsellIds, $crossSellIds, $missed] = LinkCrossSellsJob::categorizeCrossSells(
                    $rows, $migratedProducts, $upsellGroups
                );
                $unresolved += $missed;

                if ($upsellIds === [] && $crossSellIds === []) {
                    $skipped++;

                    continue;
                }

                $payload = [];
                if ($upsellIds !== []) {
                    $payload['upsell_ids'] = $upsellIds;
                }
                if ($crossSellIds !== []) {
                    $payload['cross_sell_ids'] = $crossSellIds;
                }

                if ($migration->is_dry_run) {
                    $linked++;

                    continue;
                }

                try {
                    $woo->put("products/{$wooProductId}", $payload);
                    $linked++;
                } catch (\Throwable $e) {
                    $failed++;
                    $this->log('warning', "Cross-sell update for {$shopwareId} (WC #{$wooProductId}) failed: {$e->getMessage()}", $shopwareId);
                }
            }

            $this->log('info', "Cross-sell chunk done: {$linked} updated, {$skipped} with no resolvable targets, {$unresolved} target refs skipped, {$failed} failures.");
        } finally {
            $db->disconnect();
        }
    }

    public function failed(Throwable $exception): void
    {
        MigrationLog::create([
            'migration_id' => $this->migrationId,
            'entity_type' => 'product',
            'level' => 'error',
            'message' => 'Cross-sell batch failed after retries: '.$exception->getMessage(),
            'created_at' => now(),
        ]);
    }

    protected function log(string $level, string $message, ?string $shopwareId = null): void
    {
        MigrationLog::create([
            'migration_id' => $this->migrationId,
            'entity_type' => 'product',
            'shopware_id' => $shopwareId,
            'level' => $level,
            'message' => $message,
            'created_at' => now(),
        ]);
    }
}

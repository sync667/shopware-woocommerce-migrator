<?php

namespace App\Jobs;

use App\Models\MigrationEntity;
use App\Models\MigrationLog;
use App\Models\MigrationRun;
use App\Services\ShopwareDB;
use App\Services\StateManager;
use App\Services\WooCommerceClient;
use App\Shopware\Readers\ProductReader;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class LinkCrossSellsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 5;

    public int $timeout = 3600;

    public function __construct(protected int $migrationId)
    {
        $this->onQueue('migration');
    }

    public function handle(StateManager $stateManager): void
    {
        $migration = MigrationRun::findOrFail($this->migrationId);

        if (app(\App\Services\CancellationService::class)->isCancelled($this->migrationId)) {
            $this->advanceChain();

            return;
        }

        $sw = $migration->shopwareSettings();
        $upsellGroups = array_map('mb_strtolower', $sw['upsell_group_names'] ?? []);

        $db = ShopwareDB::fromMigration($migration);
        $reader = new ProductReader($db);

        $migratedProducts = MigrationEntity::where('migration_id', $this->migrationId)
            ->where('entity_type', 'product')
            ->whereIn('status', ['success', 'skipped'])
            ->pluck('woo_id', 'shopware_id')
            ->filter()
            ->all();

        if ($migratedProducts === []) {
            $this->log('info', 'No migrated products to link.');
            $db->disconnect();
            $this->advanceChain();

            return;
        }

        $this->log('info', 'Linking cross-sells / upsells across '.count($migratedProducts).' product(s).');

        $woo = $migration->is_dry_run ? null : WooCommerceClient::fromMigration($migration);
        $linked = $skipped = $failed = 0;
        $unresolved = 0;

        foreach ($migratedProducts as $shopwareId => $wooProductId) {
            $rows = $reader->fetchCrossSells($shopwareId);
            if ($rows === []) {
                continue;
            }

            [$upsellIds, $crossSellIds, $missed] = self::categorizeCrossSells($rows, $migratedProducts, $upsellGroups);
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
                $this->log('warning', "Cross-sell update for product {$shopwareId} (WC #{$wooProductId}) failed: {$e->getMessage()}", $shopwareId);
            }
        }

        $db->disconnect();

        $this->log('info', "Cross-sells linked: {$linked} updated, {$skipped} with no resolvable targets, {$unresolved} target refs skipped (not migrated), {$failed} failures.");

        $this->advanceChain();
    }

    /**
     * Split Shopware cross-sell rows into WC upsell/cross-sell id sets based on
     * the operator-configured group-name list. Returns [upsellIds[], crossSellIds[], unresolved].
     * Pure function — no DB, no HTTP — so it's covered by unit tests.
     *
     * @param  array<int, object>  $rows  from ProductReader::fetchCrossSells
     * @param  array<string, int>  $migratedProducts  shopware_id => woo_id
     * @param  array<int, string>  $upsellGroupsLower  pre-lowercased
     * @return array{0: int[], 1: int[], 2: int}
     */
    public static function categorizeCrossSells(array $rows, array $migratedProducts, array $upsellGroupsLower): array
    {
        $upsell = [];
        $cross = [];
        $missed = 0;

        foreach ($rows as $cs) {
            $targetWooId = $migratedProducts[$cs->target_product_id ?? ''] ?? null;
            if (! $targetWooId) {
                $missed++;

                continue;
            }

            if (in_array(mb_strtolower((string) ($cs->group_name ?? '')), $upsellGroupsLower, true)) {
                $upsell[(int) $targetWooId] = true;
            } else {
                $cross[(int) $targetWooId] = true;
            }
        }

        return [array_keys($upsell), array_keys($cross), $missed];
    }

    protected function advanceChain(): void
    {
        MigrateCustomersJob::dispatch($this->migrationId);
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

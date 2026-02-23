<?php

namespace App\Jobs;

use App\Models\MigrationLog;
use App\Models\MigrationRun;
use App\Services\ShopwareDB;
use App\Services\StateManager;
use App\Shopware\Readers\ShippingMethodReader;
use App\Shopware\Transformers\ShippingMethodTransformer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class MigrateShippingMethodsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 5;

    public function __construct(protected int $migrationId) {}

    public function handle(StateManager $stateManager): void
    {
        $migration = MigrationRun::findOrFail($this->migrationId);
        $db = ShopwareDB::fromMigration($migration);
        $reader = new ShippingMethodReader($db);
        $transformer = new ShippingMethodTransformer;

        // Delta migration: only fetch updated records
        if ($migration->sync_mode === 'delta' && $migration->last_synced_at) {
            $shippingMethods = $reader->fetchUpdatedSince($migration->last_synced_at);
        } else {
            $shippingMethods = $reader->fetchAll();
        }

        foreach ($shippingMethods as $method) {
            if ($stateManager->alreadyMigrated('shipping_method', $method->id, $this->migrationId)) {
                continue;
            }

            try {
                $prices = $reader->fetchPrices($method->id);
                $data = $transformer->transform($method, $prices);

                if ($migration->is_dry_run) {
                    $stateManager->markPending('shipping_method', $method->id, $this->migrationId, $data);
                    $this->log('info', "Dry run: shipping method '{$data['method_title']}'", $method->id, 'shipping_method');

                    continue;
                }

                // Store as meta data for manual configuration in WooCommerce
                $stateManager->set('shipping_method', $method->id, crc32($data['method_id']), $this->migrationId, $data);
                $this->log('info', "Migrated shipping method '{$data['method_title']}'", $method->id, 'shipping_method');
            } catch (\Exception $e) {
                $stateManager->markFailed('shipping_method', $method->id, $this->migrationId, $e->getMessage());
                $this->log('error', "Failed: {$e->getMessage()}", $method->id, 'shipping_method');
            }
        }
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

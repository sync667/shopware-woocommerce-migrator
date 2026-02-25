<?php

namespace App\Jobs;

use App\Models\MigrationLog;
use App\Models\MigrationRun;
use App\Services\ShopwareDB;
use App\Services\StateManager;
use App\Services\WooCommerceClient;
use App\Shopware\Readers\ManufacturerReader;
use App\Shopware\Transformers\ManufacturerTransformer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class MigrateManufacturersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 5;

    public int $timeout = 3600; // 1 hour timeout for large migrations

    public function __construct(protected int $migrationId) {}

    public function handle(StateManager $stateManager): void
    {
        $migration = MigrationRun::findOrFail($this->migrationId);
        $db = ShopwareDB::fromMigration($migration);
        $woo = WooCommerceClient::fromMigration($migration);
        $reader = new ManufacturerReader($db);
        $transformer = new ManufacturerTransformer;

        // Fetch manufacturers based on sync mode
        if ($migration->sync_mode === 'delta' && $migration->last_sync_at) {
            $manufacturers = $reader->fetchUpdatedSince($migration->last_sync_at);
            $mode = 'delta (updated since '.$migration->last_sync_at->format('Y-m-d H:i:s').')';
        } else {
            $manufacturers = $reader->fetchAll();
            $mode = $migration->sync_mode === 'delta' ? 'delta (first run - all manufacturers)' : 'full';
        }

        MigrationLog::create([
            'migration_id' => $this->migrationId,
            'entity_type' => 'manufacturer',
            'level' => 'info',
            'message' => 'Processing '.count($manufacturers)." manufacturers (mode: {$mode})",
            'created_at' => now(),
        ]);

        // Create or find the global "Manufacturer" attribute in WooCommerce.
        // Its ID is stored so product migration can link terms as product attributes.
        $wooAttributeId = null;
        if (! $migration->is_dry_run) {
            try {
                $attributeResult = $woo->createOrFind(
                    'products/attributes',
                    ['name' => 'Manufacturer', 'type' => 'select', 'has_archives' => true],
                    'search',
                    'Manufacturer'
                );
                $wooAttributeId = $attributeResult['id'] ?? null;
            } catch (\Exception $e) {
                $this->log('error', 'Failed to create/find Manufacturer attribute: '.$e->getMessage(), null, 'manufacturer');
                $db->disconnect();

                return;
            }

            if (! $wooAttributeId) {
                $this->log('error', 'WooCommerce returned no ID for the Manufacturer attribute', null, 'manufacturer');
                $db->disconnect();

                return;
            }

            $stateManager->set('manufacturer_attribute', 'global', $wooAttributeId, $this->migrationId);
        }

        foreach ($manufacturers as $manufacturer) {
            if (app(\App\Services\CancellationService::class)->isCancelled($this->migrationId)) {
                return;
            }

            if ($stateManager->alreadyMigrated('manufacturer', $manufacturer->id, $this->migrationId)) {
                continue;
            }

            try {
                $data = $transformer->transform($manufacturer);

                if ($migration->is_dry_run) {
                    $stateManager->markSkipped('manufacturer', $manufacturer->id, $this->migrationId, $data);
                    $this->log('info', "Dry run: manufacturer '{$data['name']}'", $manufacturer->id, 'manufacturer');

                    continue;
                }

                $result = $woo->createOrFind(
                    "products/attributes/{$wooAttributeId}/terms",
                    ['name' => $data['name']],
                    'search',
                    $data['name']
                );

                $wooId = $result['id'] ?? null;
                if ($wooId) {
                    $stateManager->set('manufacturer', $manufacturer->id, $wooId, $this->migrationId, [
                        'name' => $data['name'],
                        'attribute_id' => $wooAttributeId,
                    ]);
                    $this->log('info', "Migrated manufacturer '{$data['name']}' → WC #{$wooId}", $manufacturer->id, 'manufacturer');
                }
            } catch (\Exception $e) {
                $stateManager->markFailed('manufacturer', $manufacturer->id, $this->migrationId, $e->getMessage());
                $this->log('error', "Failed: {$e->getMessage()}", $manufacturer->id, 'manufacturer');
            }
        }

        $db->disconnect();

        // Update last_sync_at timestamp for delta migrations
        if ($migration->sync_mode === 'delta') {
            $migration->update(['last_sync_at' => now()]);
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

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
                    $stateManager->markPending('manufacturer', $manufacturer->id, $this->migrationId, $data);
                    $this->log('info', "Dry run: manufacturer '{$data['name']}'", $manufacturer->id, 'manufacturer');

                    continue;
                }

                $result = $woo->createOrFind(
                    'products/attributes/terms',
                    ['name' => $data['name']],
                    'search',
                    $data['name']
                );

                $wooId = $result['id'] ?? null;
                if ($wooId) {
                    $stateManager->set('manufacturer', $manufacturer->id, $wooId, $this->migrationId);
                    $this->log('info', "Migrated manufacturer '{$data['name']}' → WC #{$wooId}", $manufacturer->id, 'manufacturer');
                }
            } catch (\Exception $e) {
                $stateManager->markFailed('manufacturer', $manufacturer->id, $this->migrationId, $e->getMessage());
                $this->log('error', "Failed: {$e->getMessage()}", $manufacturer->id, 'manufacturer');
            }
        }

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

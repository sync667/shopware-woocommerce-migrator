<?php

namespace App\Jobs;

use App\Models\MigrationLog;
use App\Models\MigrationRun;
use App\Services\ShopwareDB;
use App\Shopware\Readers\ProductReader;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;

class MigrateProductsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 5;

    public int $timeout = 3600; // 1 hour timeout for large migrations

    public function __construct(protected int $migrationId) {}

    public function handle(): void
    {
        $migration = MigrationRun::findOrFail($this->migrationId);

        if (app(\App\Services\CancellationService::class)->isCancelled($this->migrationId)) {
            return;
        }

        $db = ShopwareDB::fromMigration($migration);
        $reader = new ProductReader($db);

        // Fetch products based on sync mode
        if ($migration->sync_mode === 'delta' && $migration->last_sync_at) {
            $products = $reader->fetchUpdatedSince($migration->last_sync_at);
            $mode = 'delta (updated since '.$migration->last_sync_at->format('Y-m-d H:i:s').')';
        } else {
            $products = $reader->fetchAllParents();
            $mode = $migration->sync_mode === 'delta' ? 'delta (first run - all products)' : 'full';
        }

        $totalCount = count($products);

        MigrationLog::create([
            'migration_id' => $this->migrationId,
            'entity_type' => 'product',
            'level' => 'info',
            'message' => "Dispatching {$totalCount} product migration jobs (mode: {$mode})",
            'created_at' => now(),
        ]);

        // Update last_sync_at timestamp for delta migrations
        if ($migration->sync_mode === 'delta') {
            $migration->update(['last_sync_at' => now()]);
        }

        $migrationId = $this->migrationId;

        if (empty($products)) {
            MigrateCustomersJob::dispatch($migrationId);

            return;
        }

        $productJobs = array_map(
            fn ($product) => new MigrateProductJob($migrationId, $product->id),
            $products
        );

        Bus::batch($productJobs)
            ->allowFailures()
            ->then(function () use ($migrationId) {
                MigrateCustomersJob::dispatch($migrationId);
            })
            ->onQueue('products')
            ->dispatch();
    }
}

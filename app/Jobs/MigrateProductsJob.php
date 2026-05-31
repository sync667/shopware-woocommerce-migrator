<?php

namespace App\Jobs;

use App\Models\MigrationLog;
use App\Models\MigrationRun;
use App\Services\ShopwareDB;
use App\Services\StateManager;
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

    public function handle(StateManager $stateManager): void
    {
        $migration = MigrationRun::findOrFail($this->migrationId);

        if (app(\App\Services\CancellationService::class)->isCancelled($this->migrationId)) {
            return;
        }

        $db = ShopwareDB::fromMigration($migration);
        $reader = new ProductReader($db);

        if ($migration->sync_mode === 'delta' && $migration->last_sync_at) {
            $products = $reader->fetchUpdatedSince($migration->last_sync_at);
            $mode = 'delta (updated since '.$migration->last_sync_at->format('Y-m-d H:i:s').')';
        } else {
            $products = $reader->fetchAllParents();
            $mode = $migration->sync_mode === 'delta' ? 'delta (first run - all products)' : 'full';
        }

        $totalCount = count($products);
        $chunkSize = 20;
        $productIds = array_map(fn ($p) => $p->id, $products);
        $chunks = array_chunk($productIds, $chunkSize);
        $batchCount = count($chunks);

        MigrationLog::create([
            'migration_id' => $this->migrationId,
            'entity_type' => 'product',
            'level' => 'info',
            'message' => "Dispatching {$totalCount} products in {$batchCount} batches of up to {$chunkSize} (mode: {$mode})",
            'created_at' => now(),
        ]);

        foreach ($productIds as $productId) {
            $stateManager->markPending('product', $productId, $this->migrationId);
        }

        $db->disconnect();

        $migrationId = $this->migrationId;
        $isDelta = $migration->sync_mode === 'delta';

        if (empty($chunks)) {
            // No batches to wait on — safe to advance the watermark now.
            if ($isDelta) {
                $migration->update(['last_sync_at' => now()]);
            }
            MigrateCustomersJob::dispatch($migrationId);

            return;
        }

        $batchJobs = array_map(
            fn ($chunk) => new MigrateProductBatchJob($migrationId, $chunk),
            $chunks
        );

        Bus::batch($batchJobs)
            ->allowFailures()
            ->then(function () use ($migrationId, $isDelta) {
                if ($isDelta) {
                    // Advance the delta watermark only after the whole batch finished;
                    // updating before dispatch leaves still-pending records outside the
                    // next run's window and silently drops them on a later delta sync.
                    MigrationRun::where('id', $migrationId)->update(['last_sync_at' => now()]);
                }
                MigrateCustomersJob::dispatch($migrationId);
            })
            ->catch(function (\Illuminate\Bus\Batch $batch, \Throwable $e) use ($migrationId) {
                MigrationLog::create([
                    'migration_id' => $migrationId,
                    'entity_type' => 'product',
                    'level' => 'error',
                    'message' => 'Product batch error: '.$e->getMessage(),
                    'created_at' => now(),
                ]);
            })
            ->onQueue('products')
            ->dispatch();
    }
}

<?php

namespace App\Jobs;

use App\Models\MigrationLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;

/**
 * Fan-out coordinator for the four prep phases that have no inter-dependencies
 * (manufacturers, taxes, categories, product attributes). Running them as a
 * Bus::batch shortens the total prep window from sum-of-durations to
 * slowest-single-phase. On batch completion, MigrateProductsJob is dispatched
 * — products depends on all four prep phases finishing first.
 */
class PrepareCatalogJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(protected int $migrationId)
    {
        $this->onQueue('migration');
    }

    public function handle(): void
    {
        $migrationId = $this->migrationId;

        Bus::batch([
            new MigrateManufacturersJob($migrationId),
            new MigrateTaxesJob($migrationId),
            new MigrateCategoriesJob($migrationId),
            new MigrateProductAttributesJob($migrationId),
        ])
            ->name("prepare-catalog-{$migrationId}")
            ->then(function () use ($migrationId) {
                MigrateProductsJob::dispatch($migrationId);
            })
            ->catch(function (\Illuminate\Bus\Batch $batch, \Throwable $e) use ($migrationId) {
                MigrationLog::create([
                    'migration_id' => $migrationId,
                    'entity_type' => 'system',
                    'level' => 'error',
                    'message' => 'Catalog prep batch failed: '.get_class($e).': '.$e->getMessage(),
                    'context' => ['trace' => substr($e->getTraceAsString(), 0, 4000)],
                    'created_at' => now(),
                ]);
                \App\Models\MigrationRun::find($migrationId)?->markFailed();
            })
            ->onQueue('migration')
            ->dispatch();
    }
}

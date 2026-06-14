<?php

namespace App\Jobs;

use App\Models\MigrationEntity;
use App\Models\MigrationLog;
use App\Models\MigrationRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;

class LinkCrossSellsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 5;

    public int $timeout = 600;

    public const CHUNK_SIZE = 100;

    public function __construct(protected int $migrationId)
    {
        $this->onQueue('migration');
    }

    public function handle(): void
    {
        $migration = MigrationRun::findOrFail($this->migrationId);

        if (app(\App\Services\CancellationService::class)->isCancelled($this->migrationId)) {
            self::advanceChain($this->migrationId);

            return;
        }

        $migratedProducts = MigrationEntity::where('migration_id', $this->migrationId)
            ->where('entity_type', 'product')
            ->whereIn('status', ['success', 'skipped'])
            ->pluck('woo_id', 'shopware_id')
            ->filter()
            ->all();

        if ($migratedProducts === []) {
            $this->log('info', 'No migrated products to link.');
            self::advanceChain($this->migrationId);

            return;
        }

        $shopwareIds = array_keys($migratedProducts);
        $chunks = array_chunk($shopwareIds, self::CHUNK_SIZE);
        $migrationId = $this->migrationId;

        $this->log('info', 'Linking cross-sells / upsells across '.count($shopwareIds).' product(s) in '.count($chunks).' chunk(s) of '.self::CHUNK_SIZE.'.');

        $batchJobs = array_map(
            fn ($chunk) => new LinkCrossSellBatchJob($migrationId, $chunk),
            $chunks
        );

        Bus::batch($batchJobs)
            ->name("link-cross-sells-{$migrationId}")
            ->allowFailures()
            ->then(function () use ($migrationId) {
                self::advanceChain($migrationId);
            })
            ->catch(function (\Illuminate\Bus\Batch $batch, \Throwable $e) use ($migrationId) {
                MigrationLog::create([
                    'migration_id' => $migrationId,
                    'entity_type' => 'product',
                    'level' => 'error',
                    'message' => 'Cross-sell batch error: '.$e->getMessage(),
                    'created_at' => now(),
                ]);
                self::advanceChain($migrationId);
            })
            ->onQueue('migration')
            ->dispatch();
    }

    /**
     * @param  array<int, object>  $rows
     * @param  array<string, int>  $migratedProducts
     * @param  array<int, string>  $upsellGroupsLower
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

    public static function advanceChain(int $migrationId): void
    {
        MigrateCustomersJob::dispatch($migrationId);
    }

    protected function log(string $level, string $message): void
    {
        MigrationLog::create([
            'migration_id' => $this->migrationId,
            'entity_type' => 'product',
            'level' => $level,
            'message' => $message,
            'created_at' => now(),
        ]);
    }
}

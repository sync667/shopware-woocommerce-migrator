<?php

namespace App\Jobs;

use App\Models\MigrationLog;
use App\Models\MigrationRun;
use App\Services\ShopwareDB;
use App\Services\StateManager;
use App\Shopware\Readers\CustomerReader;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;

class MigrateCustomersJob implements ShouldQueue
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
        $reader = new CustomerReader($db);

        // Fetch customers based on sync mode
        if ($migration->sync_mode === 'delta' && $migration->last_sync_at) {
            $customers = $reader->fetchUpdatedSince($migration->last_sync_at);
            $mode = 'delta (updated since '.$migration->last_sync_at->format('Y-m-d H:i:s').')';
        } else {
            $customers = $reader->fetchAll();
            $mode = $migration->sync_mode === 'delta' ? 'delta (first run - all customers)' : 'full';
        }

        $totalCount = count($customers);
        $chunkSize = 200;
        $customerIds = array_map(fn ($c) => $c->id, $customers);
        $chunks = array_chunk($customerIds, $chunkSize);
        $batchCount = count($chunks);

        MigrationLog::create([
            'migration_id' => $this->migrationId,
            'entity_type' => 'customer',
            'level' => 'info',
            'message' => "Dispatching {$totalCount} customers in {$batchCount} batches of {$chunkSize} (mode: {$mode})",
            'created_at' => now(),
        ]);

        // Mark all customers as pending
        foreach ($customerIds as $customerId) {
            $stateManager->markPending('customer', $customerId, $this->migrationId);
        }

        // Update last_sync_at timestamp for delta migrations
        if ($migration->sync_mode === 'delta') {
            $migration->update(['last_sync_at' => now()]);
        }

        $migrationId = $this->migrationId;
        $cmsOptions = $migration->settings['cms_options'] ?? [];

        if (empty($chunks)) {
            $this->dispatchRemainingChain($migrationId, $cmsOptions);

            return;
        }

        $batchJobs = array_map(
            fn ($chunk) => new MigrateCustomerBatchJob($migrationId, $chunk),
            $chunks
        );

        Bus::batch($batchJobs)
            ->allowFailures()
            ->then(function () use ($migrationId, $cmsOptions) {
                MigrateCustomersJob::dispatchRemainingChain($migrationId, $cmsOptions);
            })
            ->onQueue('customers')
            ->dispatch();
    }

    public static function dispatchRemainingChain(int $migrationId, array $cmsOptions): void
    {
        $jobs = [
            new MigrateOrdersJob($migrationId),
            new MigrateCouponsJob($migrationId),
            new MigrateReviewsJob($migrationId),
            new MigrateShippingMethodsJob($migrationId),
            new MigratePaymentMethodsJob($migrationId),
            new MigrateSeoUrlsJob($migrationId),
        ];

        if (! empty($cmsOptions['migrate_all'])) {
            $jobs[] = new MigrateCmsPagesJob($migrationId);
        } elseif (! empty($cmsOptions['selected_ids'])) {
            $jobs[] = new MigrateCmsPagesJob($migrationId, $cmsOptions['selected_ids']);
        }

        $jobs[] = function () use ($migrationId) {
            $migration = MigrationRun::findOrFail($migrationId);
            if ($migration->status === 'running') {
                $migration->markCompleted();
            }
            app(\App\Services\CancellationService::class)->clear($migrationId);
        };

        Bus::chain($jobs)->dispatch();
    }

    protected function log(string $level, string $message, ?string $shopwareId = null): void
    {
        MigrationLog::create([
            'migration_id' => $this->migrationId,
            'entity_type' => 'customer',
            'shopware_id' => $shopwareId,
            'level' => $level,
            'message' => $message,
            'created_at' => now(),
        ]);
    }
}

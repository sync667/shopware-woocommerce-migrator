<?php

namespace App\Jobs;

use App\Models\MigrationLog;
use App\Models\MigrationRun;
use App\Services\ShopwareDB;
use App\Services\StateManager;
use App\Services\WooCommerceClient;
use App\Shopware\Readers\OrderReader;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;

class MigrateOrdersJob implements ShouldQueue
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
        $reader = new OrderReader($db);

        // Fetch orders based on sync mode
        if ($migration->sync_mode === 'delta' && $migration->last_sync_at) {
            $orders = $reader->fetchUpdatedSince($migration->last_sync_at);
            $mode = 'delta (updated since '.$migration->last_sync_at->format('Y-m-d H:i:s').')';
        } else {
            $orders = $reader->fetchAll();
            $mode = $migration->sync_mode === 'delta' ? 'delta (first run - all orders)' : 'full';
        }

        $totalCount = count($orders);
        $chunkSize = 50;
        $orderIds = array_map(fn ($o) => $o->id, $orders);
        $chunks = array_chunk($orderIds, $chunkSize);
        $batchCount = count($chunks);

        MigrationLog::create([
            'migration_id' => $this->migrationId,
            'entity_type' => 'order',
            'level' => 'info',
            'message' => "Dispatching {$totalCount} orders in {$batchCount} batches of {$chunkSize} (mode: {$mode})",
            'created_at' => now(),
        ]);

        // Mark all orders as pending
        foreach ($orderIds as $orderId) {
            $stateManager->markPending('order', $orderId, $this->migrationId);
        }

        $db->disconnect();

        // Update last_sync_at timestamp for delta migrations
        if ($migration->sync_mode === 'delta') {
            $migration->update(['last_sync_at' => now()]);
        }

        $migrationId = $this->migrationId;

        // If customers job didn't disable emails (e.g. zero customers), do it now.
        if (! $migration->is_dry_run && empty($migration->setting('_wc_email_backup'))) {
            try {
                $woo = WooCommerceClient::fromMigration($migration);
                $emailBackup = $woo->disableEmails();
                $migration->update([
                    'settings' => array_merge($migration->settings ?? [], ['_wc_email_backup' => $emailBackup]),
                ]);
                $this->log('info', 'Disabled WooCommerce email notifications for order migration');
            } catch (\Exception $e) {
                $this->log('warning', 'Could not disable WooCommerce emails: '.$e->getMessage());
            }
        }

        if (empty($chunks)) {
            self::restoreEmailsAndDispatchCoupons($migrationId);

            return;
        }

        $batchJobs = array_map(
            fn ($chunk) => new MigrateOrderBatchJob($migrationId, $chunk),
            $chunks
        );

        Bus::batch($batchJobs)
            ->allowFailures()
            ->then(function () use ($migrationId) {
                MigrateOrdersJob::restoreEmailsAndDispatchCoupons($migrationId);
            })
            ->catch(function (\Illuminate\Bus\Batch $batch, \Throwable $e) use ($migrationId) {
                MigrationLog::create([
                    'migration_id' => $migrationId,
                    'entity_type' => 'order',
                    'level' => 'error',
                    'message' => 'Order batch error: '.$e->getMessage(),
                    'created_at' => now(),
                ]);
            })
            ->onQueue('orders')
            ->dispatch();
    }

    /**
     * Restore WooCommerce email settings from the migration backup, clear the backup,
     * then advance the chain to coupons. Called from both the then() callback and the
     * empty-chunks early return so emails are always restored regardless of order count.
     */
    public static function restoreEmailsAndDispatchCoupons(int $migrationId): void
    {
        $migration = MigrationRun::find($migrationId);

        if ($migration) {
            $emailBackup = $migration->setting('_wc_email_backup', []);

            if (! empty($emailBackup)) {
                try {
                    $woo = WooCommerceClient::fromMigration($migration);
                    $woo->restoreEmails($emailBackup);

                    $settings = $migration->settings ?? [];
                    unset($settings['_wc_email_backup']);
                    $migration->update(['settings' => $settings]);

                    MigrationLog::create([
                        'migration_id' => $migrationId,
                        'entity_type' => 'order',
                        'level' => 'info',
                        'message' => 'Restored WooCommerce email notifications after order migration',
                        'created_at' => now(),
                    ]);
                } catch (\Exception $e) {
                    MigrationLog::create([
                        'migration_id' => $migrationId,
                        'entity_type' => 'order',
                        'level' => 'warning',
                        'message' => 'Could not restore WooCommerce email settings: '.$e->getMessage().'. Please re-enable them manually in WooCommerce → Settings → Emails.',
                        'created_at' => now(),
                    ]);
                }
            }
        }

        MigrateCouponsJob::dispatch($migrationId);
    }

    protected function log(string $level, string $message, ?string $shopwareId = null): void
    {
        MigrationLog::create([
            'migration_id' => $this->migrationId,
            'entity_type' => 'order',
            'shopware_id' => $shopwareId,
            'level' => $level,
            'message' => $message,
            'created_at' => now(),
        ]);
    }
}

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

        foreach ($orderIds as $orderId) {
            $stateManager->markPending('order', $orderId, $this->migrationId);
        }

        $db->disconnect();

        if (! self::preflightOrderIdCollisions($migration, $orders)) {
            return;
        }

        $migrationId = $this->migrationId;
        $isDelta = $migration->sync_mode === 'delta';

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
            if ($isDelta) {
                $migration->update(['last_sync_at' => now()]);
            }
            self::restoreEmailsAndDispatchCoupons($migrationId);

            return;
        }

        $batchJobs = array_map(
            fn ($chunk) => new MigrateOrderBatchJob($migrationId, $chunk),
            $chunks
        );

        Bus::batch($batchJobs)
            ->allowFailures()
            ->then(function () use ($migrationId, $isDelta) {
                if ($isDelta) {
                    MigrationRun::where('id', $migrationId)->update(['last_sync_at' => now()]);
                }
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

            self::bumpOrderAutoIncrement($migration);

            MigrateOrderHistoryJob::dispatch($migrationId);
        }

        MigrateCouponsJob::dispatch($migrationId);
    }

    /**
     * Pre-flight: when preserve_order_ids is on, ensure no NON-shop_order posts
     * occupy the [min(order_number), max(order_number)] range. Abort the orders
     * phase cleanly if collisions exist — the operator must resolve manually
     * (delete those posts, or disable preserve_order_ids for this run).
     *
     * @param  array<int, object>  $orders
     */
    protected static function preflightOrderIdCollisions(MigrationRun $migration, array $orders): bool
    {
        if ($migration->is_dry_run) {
            return true;
        }

        $woo = $migration->woocommerceSettings();
        if (empty($woo['preserve_order_ids'])) {
            return true;
        }

        $db = \App\Services\WooCommerceDB::fromMigration($migration);
        if (! $db->isConfigured()) {
            MigrationLog::create([
                'migration_id' => $migration->id,
                'entity_type' => 'order',
                'level' => 'warning',
                'message' => 'preserve_order_ids is on but WC DB credentials are not set — falling back to auto-assigned ids.',
                'created_at' => now(),
            ]);

            return true;
        }

        $numbers = [];
        foreach ($orders as $o) {
            if (is_numeric($o->order_number ?? null)) {
                $numbers[] = (int) $o->order_number;
            }
        }
        if ($numbers === []) {
            $db->disconnect();

            return true;
        }

        $min = min($numbers);
        $max = max($numbers);

        try {
            $conflicts = $db->findPostIdCollisions($min, $max);
        } catch (\Throwable $e) {
            MigrationLog::create([
                'migration_id' => $migration->id,
                'entity_type' => 'order',
                'level' => 'warning',
                'message' => 'Pre-flight collision check failed ('.$e->getMessage().') — falling back to auto-assigned ids.',
                'created_at' => now(),
            ]);
            $db->disconnect();

            return true;
        } finally {
            $db->disconnect();
        }

        if ($conflicts === []) {
            MigrationLog::create([
                'migration_id' => $migration->id,
                'entity_type' => 'order',
                'level' => 'info',
                'message' => "Pre-flight: ID range [{$min}..{$max}] is clean. Will renumber each order to its Shopware number.",
                'created_at' => now(),
            ]);

            return true;
        }

        $sample = array_slice($conflicts, 0, 10);
        $list = implode(', ', array_map(fn ($c) => "#{$c->ID} ({$c->post_type})", $sample));

        // When cleanup is on the operator has opted into wiping the target store;
        // surviving non-order posts in the range are a normal CMS/plugin reality
        // and we let per-order renumber handle each collision individually
        // (warning + keep auto-id). Only abort when cleanup is OFF, since then
        // the operator hasn't given us license to leave data in inconsistent shape.
        if ($migration->clean_woocommerce) {
            MigrationLog::create([
                'migration_id' => $migration->id,
                'entity_type' => 'order',
                'level' => 'info',
                'message' => 'Pre-flight: '.count($conflicts)." non-order posts in [{$min}..{$max}] — those specific renumbers will fall back to auto-ids. Sample: {$list}",
                'created_at' => now(),
            ]);

            return true;
        }

        MigrationLog::create([
            'migration_id' => $migration->id,
            'entity_type' => 'order',
            'level' => 'error',
            'message' => 'preserve_order_ids aborted: '.count($conflicts).' non-order posts already occupy IDs in ['.$min.'..'.$max.']. Enable cleanup or resolve manually. Sample: '.$list,
            'created_at' => now(),
        ]);

        $migration->markFailed();

        return false;
    }

    /**
     * Push wp_posts.AUTO_INCREMENT past the highest migrated _order_number so
     * subsequent NEW orders get post.IDs (and default display numbers) above
     * the legacy Shopware range. No-op when WC DB credentials aren't set.
     */
    protected static function bumpOrderAutoIncrement(MigrationRun $migration): void
    {
        $db = \App\Services\WooCommerceDB::fromMigration($migration);

        if (! $db->isConfigured()) {
            return;
        }

        try {
            $highest = $db->highestStampedOrderNumber();
            if ($highest === null) {
                return;
            }

            $minimum = $highest + 1;
            $bumped = $db->bumpPostsAutoIncrementTo($minimum);

            MigrationLog::create([
                'migration_id' => $migration->id,
                'entity_type' => 'order',
                'level' => 'info',
                'message' => $bumped
                    ? "Bumped wp_posts.AUTO_INCREMENT to {$minimum} (next new order = #{$minimum})"
                    : "wp_posts.AUTO_INCREMENT already above {$minimum}, left unchanged",
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            MigrationLog::create([
                'migration_id' => $migration->id,
                'entity_type' => 'order',
                'level' => 'warning',
                'message' => 'AUTO_INCREMENT bump failed: '.$e->getMessage(),
                'created_at' => now(),
            ]);
        } finally {
            $db->disconnect();
        }
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

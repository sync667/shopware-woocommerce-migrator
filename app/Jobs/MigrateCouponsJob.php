<?php

namespace App\Jobs;

use App\Models\MigrationLog;
use App\Models\MigrationRun;
use App\Services\ShopwareDB;
use App\Services\StateManager;
use App\Services\WooCommerceClient;
use App\Shopware\Readers\CouponReader;
use App\Shopware\Transformers\CouponTransformer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class MigrateCouponsJob implements ShouldQueue
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
        $reader = new CouponReader($db);
        $transformer = new CouponTransformer;

        // Fetch coupons based on sync mode
        if ($migration->sync_mode === 'delta' && $migration->last_sync_at) {
            $promotions = $reader->fetchUpdatedSince($migration->last_sync_at);
            $mode = 'delta (updated since '.$migration->last_sync_at->format('Y-m-d H:i:s').')';
        } else {
            $promotions = $reader->fetchAll();
            $mode = $migration->sync_mode === 'delta' ? 'delta (first run - all coupons)' : 'full';
        }

        MigrationLog::create([
            'migration_id' => $this->migrationId,
            'entity_type' => 'coupon',
            'level' => 'info',
            'message' => 'Processing '.count($promotions)." coupons (mode: {$mode})",
            'created_at' => now(),
        ]);

        foreach ($promotions as $promotion) {
            if (app(\App\Services\CancellationService::class)->isCancelled($this->migrationId)) {
                return;
            }

            if ($stateManager->alreadyMigrated('coupon', $promotion->id, $this->migrationId)) {
                continue;
            }

            try {
                $discounts = $reader->fetchDiscounts($promotion->id);

                if ($promotion->use_individual_codes ?? false) {
                    $codes = $reader->fetchIndividualCodes($promotion->id);
                    foreach ($codes as $codeRow) {
                        $data = $transformer->transform($promotion, $discounts, $codeRow->code);

                        if ($migration->is_dry_run) {
                            $this->log('info', "Dry run: coupon '{$codeRow->code}'", $promotion->id);

                            continue;
                        }

                        $woo->post('coupons', $data);
                    }
                } else {
                    $data = $transformer->transform($promotion, $discounts);

                    if ($migration->is_dry_run) {
                        $stateManager->markPending('coupon', $promotion->id, $this->migrationId, $data);
                        $this->log('info', "Dry run: coupon '{$data['code']}'", $promotion->id);

                        continue;
                    }

                    $result = $woo->post('coupons', $data);
                    $wooId = $result['id'] ?? null;

                    if ($wooId) {
                        $stateManager->set('coupon', $promotion->id, $wooId, $this->migrationId);
                        $this->log('info', "Migrated coupon '{$data['code']}' → WC #{$wooId}", $promotion->id);
                    }
                }
            } catch (\Exception $e) {
                $stateManager->markFailed('coupon', $promotion->id, $this->migrationId, $e->getMessage());
                $this->log('error', "Failed: {$e->getMessage()}", $promotion->id);
            }
        }

        // Update last_sync_at timestamp for delta migrations
        if ($migration->sync_mode === 'delta') {
            $migration->update(['last_sync_at' => now()]);
        }
    }

    protected function log(string $level, string $message, ?string $shopwareId = null): void
    {
        MigrationLog::create([
            'migration_id' => $this->migrationId,
            'entity_type' => 'coupon',
            'shopware_id' => $shopwareId,
            'level' => $level,
            'message' => $message,
            'created_at' => now(),
        ]);
    }
}

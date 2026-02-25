<?php

namespace App\Jobs;

use App\Models\MigrationLog;
use App\Models\MigrationRun;
use App\Services\ShopwareDB;
use App\Services\StateManager;
use App\Services\WooCommerceClient;
use App\Shopware\Readers\CouponReader;
use App\Shopware\Transformers\CouponTransformer;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class MigrateCouponBatchJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 5;

    public int $timeout = 600; // 10 minutes per batch

    public function __construct(
        protected int $migrationId,
        protected array $couponIds
    ) {}

    public function handle(StateManager $stateManager): void
    {
        $migration = MigrationRun::findOrFail($this->migrationId);

        if (app(\App\Services\CancellationService::class)->isCancelled($this->migrationId)) {
            $this->batch()?->cancel();

            return;
        }

        $db = ShopwareDB::fromMigration($migration);
        $woo = WooCommerceClient::fromMigration($migration);
        $reader = new CouponReader($db);
        $transformer = new CouponTransformer;

        try {
            foreach ($this->couponIds as $couponId) {
                if (app(\App\Services\CancellationService::class)->isCancelled($this->migrationId)) {
                    $this->batch()?->cancel();

                    return;
                }

                if ($stateManager->alreadyMigrated('coupon', $couponId, $this->migrationId)) {
                    continue;
                }

                try {
                    $promotion = $reader->fetchOne($couponId);

                    if ($promotion === null) {
                        $stateManager->markFailed('coupon', $couponId, $this->migrationId, 'Coupon not found in Shopware DB');
                        $this->log('warning', 'Coupon not found in Shopware DB', $couponId);

                        continue;
                    }
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
                            $stateManager->markSkipped('coupon', $promotion->id, $this->migrationId, $data);
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
                } catch (\Throwable $e) {
                    $stateManager->markFailed('coupon', $couponId, $this->migrationId, $e->getMessage());
                    $this->log('error', "Failed: {$e->getMessage()}", $couponId);
                }
            }
        } finally {
            $db->disconnect();
        }
    }

    /**
     * Mark all coupons in this batch as failed if the job itself exhausts its retries.
     */
    public function failed(Throwable $exception): void
    {
        $stateManager = app(StateManager::class);

        foreach ($this->couponIds as $couponId) {
            $stateManager->markFailed('coupon', $couponId, $this->migrationId, $exception->getMessage());
        }

        MigrationLog::create([
            'migration_id' => $this->migrationId,
            'entity_type' => 'coupon',
            'level' => 'error',
            'message' => 'Batch job failed after retries: '.$exception->getMessage(),
            'created_at' => now(),
        ]);
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

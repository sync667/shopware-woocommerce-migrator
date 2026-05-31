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
                        $codesProcessed = 0;
                        $codesFailed = 0;
                        foreach ($codes as $codeRow) {
                            if (empty($codeRow->code)) {
                                $this->log('warning', 'Skipping individual coupon code: empty code', $promotion->id);

                                continue;
                            }

                            // Per-code state so a job retry after a partial run doesn't
                            // re-POST every code in the set and create duplicates in WC.
                            $codeKey = $promotion->id.':'.$codeRow->code;
                            if ($stateManager->alreadyMigrated('coupon_code', $codeKey, $this->migrationId)) {
                                $codesProcessed++;

                                continue;
                            }

                            try {
                                // Transform-and-POST inside the inner try so a single bad
                                // code (e.g. malformed discount payload from transform()) only
                                // taints that one code, not the whole parent promotion.
                                $data = $transformer->transform($promotion, $discounts, $codeRow->code);

                                if ($migration->is_dry_run) {
                                    $stateManager->markSkipped('coupon_code', $codeKey, $this->migrationId, $data);
                                    $this->log('info', "Dry run: coupon '{$codeRow->code}'", $promotion->id);
                                    $codesProcessed++;

                                    continue;
                                }

                                // createOrFind tolerates an existing-code 400/409, which
                                // is the most likely conflict when retrying after a partial
                                // batch.
                                $result = $woo->createOrFind('coupons', $data, 'code', $codeRow->code);
                                $wooId = $result['id'] ?? null;
                                if ($wooId) {
                                    $stateManager->set(
                                        'coupon_code',
                                        $codeKey,
                                        (int) $wooId,
                                        $this->migrationId,
                                        ['promotion_id' => $promotion->id, 'code' => $codeRow->code]
                                    );
                                    $codesProcessed++;
                                } else {
                                    $stateManager->markFailed('coupon_code', $codeKey, $this->migrationId, 'WooCommerce returned no id for coupon code');
                                    $codesFailed++;
                                }
                            } catch (\Exception $e) {
                                // \Exception (not \Throwable) so genuine PHP errors propagate to the
                                // batch retry mechanism — only API/data exceptions are tolerated here.
                                $stateManager->markFailed('coupon_code', $codeKey, $this->migrationId, $e->getMessage());
                                $this->log('warning', "Code '{$codeRow->code}' failed: {$e->getMessage()}", $promotion->id);
                                $codesFailed++;
                            }
                        }

                        // Mark the parent promotion success as long as we made forward progress.
                        // Sentinel woo_id=0 means "no single WC counterpart" — each code is its
                        // own coupon. The outer alreadyMigrated() short-circuit prevents future
                        // re-iteration for promotions where every code is already in some state.
                        if ($codesFailed === 0 && $codesProcessed > 0) {
                            $stateManager->set('coupon', $promotion->id, 0, $this->migrationId, [
                                'individual_codes' => true,
                                'codes_processed' => $codesProcessed,
                            ]);
                        } elseif ($codesFailed > 0) {
                            $this->log(
                                'warning',
                                "Promotion has {$codesFailed} failed individual code(s); parent not marked success — retry needed",
                                $promotion->id
                            );
                        }

                        continue;
                    }

                    $data = $transformer->transform($promotion, $discounts);

                    if (empty($data['code'])) {
                        $stateManager->markFailed('coupon', $promotion->id, $this->migrationId, 'Empty coupon code');
                        $this->log('warning', 'Skipping coupon: empty code', $promotion->id);

                        continue;
                    }

                    if ($migration->is_dry_run) {
                        $stateManager->markSkipped('coupon', $promotion->id, $this->migrationId, $data);
                        $this->log('info', "Dry run: coupon '{$data['code']}'", $promotion->id);

                        continue;
                    }

                    // Use createOrFind on the single-code branch too so a retried POST
                    // hits the existing rule (by code) instead of failing or duplicating.
                    $result = $woo->createOrFind('coupons', $data, 'code', $data['code']);
                    $wooId = $result['id'] ?? null;

                    if ($wooId) {
                        $stateManager->set('coupon', $promotion->id, (int) $wooId, $this->migrationId);
                        $this->log('info', "Migrated coupon '{$data['code']}' → WC #{$wooId}", $promotion->id);
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

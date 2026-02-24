<?php

namespace App\Jobs;

use App\Models\MigrationLog;
use App\Models\MigrationRun;
use App\Services\ShopwareDB;
use App\Services\StateManager;
use App\Services\WooCommerceClient;
use App\Shopware\Readers\ReviewReader;
use App\Shopware\Transformers\ReviewTransformer;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class MigrateReviewBatchJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 5;

    public int $timeout = 600; // 10 minutes per batch

    public function __construct(
        protected int $migrationId,
        protected array $reviewIds
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
        $reader = new ReviewReader($db);
        $transformer = new ReviewTransformer;

        try {
            foreach ($this->reviewIds as $reviewId) {
                if (app(\App\Services\CancellationService::class)->isCancelled($this->migrationId)) {
                    $this->batch()?->cancel();

                    return;
                }

                if ($stateManager->alreadyMigrated('review', $reviewId, $this->migrationId)) {
                    continue;
                }

                try {
                    $reviews = $db->select("
                    SELECT
                        LOWER(HEX(r.id)) AS id,
                        LOWER(HEX(r.product_id)) AS product_id,
                        r.title,
                        r.content,
                        r.points,
                        r.status,
                        r.created_at,
                        COALESCE(
                            CONCAT(c.first_name, ' ', c.last_name),
                            r.external_user
                        ) AS reviewer_name,
                        COALESCE(c.email, r.external_email) AS reviewer_email
                    FROM product_review r
                    LEFT JOIN customer c ON c.id = r.customer_id
                    WHERE LOWER(HEX(r.id)) = ?
                ", [$reviewId]);

                    if (empty($reviews)) {
                        $stateManager->markFailed('review', $reviewId, $this->migrationId, 'Review not found in Shopware DB');
                        $this->log('warning', 'Review not found in Shopware DB', $reviewId);

                        continue;
                    }

                    $review = $reviews[0];

                    $wooProductId = $stateManager->get('product', $review->product_id, $this->migrationId);

                    if (! $wooProductId) {
                        $this->log('warning', 'Skipping review: product not yet migrated', $review->id);
                        $stateManager->markFailed('review', $review->id, $this->migrationId, 'Product not migrated');

                        continue;
                    }

                    $data = $transformer->transform($review, $wooProductId);

                    if ($migration->is_dry_run) {
                        $stateManager->markSkipped('review', $review->id, $this->migrationId, $data);
                        $this->log('info', "Dry run: review for product WC #{$wooProductId}", $review->id);

                        continue;
                    }

                    $result = $woo->post('products/reviews', $data);
                    $wooId = $result['id'] ?? null;

                    if ($wooId) {
                        $stateManager->set('review', $review->id, $wooId, $this->migrationId);
                        $this->log('info', "Migrated review → WC #{$wooId}", $review->id);
                    }
                } catch (\Throwable $e) {
                    $stateManager->markFailed('review', $reviewId, $this->migrationId, $e->getMessage());
                    $this->log('error', "Failed: {$e->getMessage()}", $reviewId);
                }
            }
        } finally {
            $db->disconnect();
        }
    }

    /**
     * Mark all reviews in this batch as failed if the job itself exhausts its retries.
     */
    public function failed(Throwable $exception): void
    {
        $stateManager = app(StateManager::class);

        foreach ($this->reviewIds as $reviewId) {
            $stateManager->markFailed('review', $reviewId, $this->migrationId, $exception->getMessage());
        }

        MigrationLog::create([
            'migration_id' => $this->migrationId,
            'entity_type' => 'review',
            'level' => 'error',
            'message' => 'Batch job failed after retries: '.$exception->getMessage(),
            'created_at' => now(),
        ]);
    }

    protected function log(string $level, string $message, ?string $shopwareId = null): void
    {
        MigrationLog::create([
            'migration_id' => $this->migrationId,
            'entity_type' => 'review',
            'shopware_id' => $shopwareId,
            'level' => $level,
            'message' => $message,
            'created_at' => now(),
        ]);
    }
}

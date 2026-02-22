<?php

namespace App\Jobs;

use App\Models\MigrationLog;
use App\Models\MigrationRun;
use App\Services\ShopwareDB;
use App\Services\StateManager;
use App\Services\WooCommerceClient;
use App\Shopware\Readers\ReviewReader;
use App\Shopware\Transformers\ReviewTransformer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class MigrateReviewsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 5;

    public function __construct(protected int $migrationId) {}

    public function handle(StateManager $stateManager): void
    {
        $migration = MigrationRun::findOrFail($this->migrationId);
        $db = ShopwareDB::fromMigration($migration);
        $woo = WooCommerceClient::fromMigration($migration);
        $reader = new ReviewReader($db);
        $transformer = new ReviewTransformer;

        $reviews = $reader->fetchAll();

        foreach ($reviews as $review) {
            if ($stateManager->alreadyMigrated('review', $review->id, $this->migrationId)) {
                continue;
            }

            try {
                $wooProductId = $stateManager->get('product', $review->product_id, $this->migrationId);

                if (! $wooProductId) {
                    $this->log('warning', 'Skipping review: product not yet migrated', $review->id);
                    $stateManager->markFailed('review', $review->id, $this->migrationId, 'Product not migrated');

                    continue;
                }

                $data = $transformer->transform($review, $wooProductId);

                if ($migration->is_dry_run) {
                    $stateManager->markPending('review', $review->id, $this->migrationId, $data);
                    $this->log('info', "Dry run: review for product WC #{$wooProductId}", $review->id);

                    continue;
                }

                $result = $woo->post('products/reviews', $data);
                $wooId = $result['id'] ?? null;

                if ($wooId) {
                    $stateManager->set('review', $review->id, $wooId, $this->migrationId);
                    $this->log('info', "Migrated review → WC #{$wooId}", $review->id);
                }
            } catch (\Exception $e) {
                $stateManager->markFailed('review', $review->id, $this->migrationId, $e->getMessage());
                $this->log('error', "Failed: {$e->getMessage()}", $review->id);
            }
        }
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

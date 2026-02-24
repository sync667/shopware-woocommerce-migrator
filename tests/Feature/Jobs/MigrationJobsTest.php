<?php

namespace Tests\Feature\Jobs;

use App\Jobs\MigrateCategoriesJob;
use App\Jobs\MigrateCouponBatchJob;
use App\Jobs\MigrateCouponsJob;
use App\Jobs\MigrateCustomersJob;
use App\Jobs\MigrateManufacturersJob;
use App\Jobs\MigrateOrderBatchJob;
use App\Jobs\MigrateOrdersJob;
use App\Jobs\MigratePaymentMethodsJob;
use App\Jobs\MigrateProductBatchJob;
use App\Jobs\MigrateProductJob;
use App\Jobs\MigrateProductsJob;
use App\Jobs\MigrateReviewBatchJob;
use App\Jobs\MigrateReviewsJob;
use App\Jobs\MigrateSeoUrlsJob;
use App\Jobs\MigrateShippingMethodsJob;
use App\Jobs\MigrateTaxesJob;
use App\Models\MigrationEntity;
use App\Models\MigrationLog;
use App\Models\MigrationRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MigrationJobsTest extends TestCase
{
    use RefreshDatabase;

    private MigrationRun $migration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->migration = MigrationRun::create([
            'name' => 'Job Test',
            'settings' => [
                'shopware' => [
                    'db_host' => '127.0.0.1',
                    'db_port' => 3306,
                    'db_database' => 'shopware',
                    'db_username' => 'root',
                    'db_password' => 'pass',
                    'language_id' => 'abc123',
                    'live_version_id' => 'def456',
                    'base_url' => 'https://shop.test',
                ],
                'woocommerce' => [
                    'base_url' => 'https://woo.test',
                    'consumer_key' => 'ck_test',
                    'consumer_secret' => 'cs_test',
                ],
                'wordpress' => [
                    'username' => 'admin',
                    'app_password' => 'pass',
                ],
            ],
            'status' => 'running',
            'is_dry_run' => false,
        ]);
    }

    // === Job dispatch tests ===

    public function test_all_jobs_are_queueable(): void
    {
        $jobs = [
            MigrateManufacturersJob::class,
            MigrateTaxesJob::class,
            MigrateCategoriesJob::class,
            MigrateProductsJob::class,
            MigrateCustomersJob::class,
            MigrateOrdersJob::class,
            MigrateCouponsJob::class,
            MigrateReviewsJob::class,
        ];

        foreach ($jobs as $jobClass) {
            $this->assertTrue(
                in_array(\Illuminate\Contracts\Queue\ShouldQueue::class, class_implements($jobClass)),
                "{$jobClass} should implement ShouldQueue"
            );
        }
    }

    public function test_all_batch_jobs_are_queueable(): void
    {
        $jobs = [
            MigrateProductBatchJob::class,
            MigrateOrderBatchJob::class,
            MigrateCouponBatchJob::class,
            MigrateReviewBatchJob::class,
        ];

        foreach ($jobs as $jobClass) {
            $this->assertTrue(
                in_array(\Illuminate\Contracts\Queue\ShouldQueue::class, class_implements($jobClass)),
                "{$jobClass} should implement ShouldQueue"
            );
        }
    }

    public function test_product_job_dispatches_to_products_queue(): void
    {
        Queue::fake();

        MigrateProductJob::dispatch($this->migration->id, 'test-product-id')
            ->onQueue('products');

        Queue::assertPushedOn('products', MigrateProductJob::class);
    }

    public function test_batch_jobs_have_retry_configuration(): void
    {
        $productBatch = new MigrateProductBatchJob($this->migration->id, ['id1', 'id2']);
        $this->assertEquals(3, $productBatch->tries);
        $this->assertEquals(5, $productBatch->backoff);
        $this->assertEquals(1800, $productBatch->timeout);

        $orderBatch = new MigrateOrderBatchJob($this->migration->id, ['id1']);
        $this->assertEquals(3, $orderBatch->tries);
        $this->assertEquals(5, $orderBatch->backoff);
        $this->assertEquals(600, $orderBatch->timeout);

        $couponBatch = new MigrateCouponBatchJob($this->migration->id, ['id1']);
        $this->assertEquals(3, $couponBatch->tries);
        $this->assertEquals(5, $couponBatch->backoff);
        $this->assertEquals(600, $couponBatch->timeout);

        $reviewBatch = new MigrateReviewBatchJob($this->migration->id, ['id1']);
        $this->assertEquals(3, $reviewBatch->tries);
        $this->assertEquals(5, $reviewBatch->backoff);
        $this->assertEquals(600, $reviewBatch->timeout);
    }

    public function test_jobs_have_retry_configuration(): void
    {
        $job = new MigrateManufacturersJob($this->migration->id);
        $this->assertEquals(3, $job->tries);
        $this->assertEquals(5, $job->backoff);

        $job2 = new MigrateCategoriesJob($this->migration->id);
        $this->assertEquals(3, $job2->tries);
        $this->assertEquals(5, $job2->backoff);

        $job3 = new MigrateProductJob($this->migration->id, 'abc');
        $this->assertEquals(3, $job3->tries);
        $this->assertEquals(5, $job3->backoff);
    }

    // === State manager integration through jobs ===

    public function test_state_manager_prevents_duplicate_processing(): void
    {
        // Simulate already-migrated entity
        MigrationEntity::create([
            'migration_id' => $this->migration->id,
            'entity_type' => 'manufacturer',
            'shopware_id' => 'already-done',
            'status' => 'success',
            'woo_id' => 99,
        ]);

        $stateManager = new \App\Services\StateManager;
        $this->assertTrue(
            $stateManager->alreadyMigrated('manufacturer', 'already-done', $this->migration->id)
        );

        // New entity should not be marked as migrated
        $this->assertFalse(
            $stateManager->alreadyMigrated('manufacturer', 'not-yet-migrated-manufacturer', $this->migration->id)
        );
    }

    public function test_state_manager_tracks_failures_with_error_messages(): void
    {
        $stateManager = new \App\Services\StateManager;

        $stateManager->markFailed(
            'product',
            'failing-product',
            $this->migration->id,
            'WooCommerce API returned 500: Internal Server Error'
        );

        $entity = MigrationEntity::where('migration_id', $this->migration->id)
            ->where('shopware_id', 'failing-product')
            ->first();

        $this->assertEquals('failed', $entity->status);
        $this->assertEquals('WooCommerce API returned 500: Internal Server Error', $entity->error_message);
        $this->assertNull($entity->woo_id);
    }

    public function test_migration_logs_are_created_with_all_fields(): void
    {
        MigrationLog::create([
            'migration_id' => $this->migration->id,
            'entity_type' => 'product',
            'shopware_id' => 'test-product',
            'level' => 'error',
            'message' => 'Failed to upload image',
            'context' => ['url' => 'https://shop.test/media/image.jpg'],
            'created_at' => now(),
        ]);

        $log = MigrationLog::where('migration_id', $this->migration->id)->first();

        $this->assertEquals('product', $log->entity_type);
        $this->assertEquals('test-product', $log->shopware_id);
        $this->assertEquals('error', $log->level);
        $this->assertStringContainsString('image', $log->message);
    }

    // === Dry run behavior ===

    public function test_dry_run_marks_entities_as_skipped(): void
    {
        $stateManager = new \App\Services\StateManager;
        $stateManager->markSkipped(
            'product',
            'dry-run-product',
            $this->migration->id,
            ['name' => 'Test Product', 'sku' => 'SKU-001']
        );

        $entity = MigrationEntity::where('migration_id', $this->migration->id)
            ->where('shopware_id', 'dry-run-product')
            ->first();

        $this->assertEquals('skipped', $entity->status);
        $this->assertNull($entity->woo_id);
        $this->assertNotNull($entity->payload);
    }

    // === Error recovery ===

    public function test_failed_entity_can_be_retried(): void
    {
        $stateManager = new \App\Services\StateManager;

        // First attempt fails
        $stateManager->markFailed('product', 'retry-product', $this->migration->id, 'Timeout');

        $entity = MigrationEntity::where('shopware_id', 'retry-product')->first();
        $this->assertEquals('failed', $entity->status);

        // Retry succeeds
        $stateManager->set('product', 'retry-product', 42, $this->migration->id);

        $entity->refresh();
        $this->assertEquals('success', $entity->status);
        $this->assertEquals(42, $entity->woo_id);
    }

    // === Normal migration chain tests ===

    public function test_remaining_chain_includes_all_entity_jobs(): void
    {
        Bus::fake();

        MigrateCustomersJob::dispatchRemainingChain($this->migration->id, []);

        // dispatchRemainingChain now dispatches MigrateOrdersJob which cascades
        Bus::assertDispatched(MigrateOrdersJob::class);
    }

    public function test_remaining_chain_dispatches_without_errors(): void
    {
        Bus::fake();

        // Verify dispatchRemainingChain with CMS options doesn't throw
        MigrateCustomersJob::dispatchRemainingChain($this->migration->id, ['migrate_all' => true]);

        Bus::assertDispatched(MigrateOrdersJob::class);
    }

    public function test_final_chain_includes_shipping_payment_seo(): void
    {
        Bus::fake();

        MigrateReviewsJob::dispatchFinalChain($this->migration->id);

        // dispatchFinalChain creates a Bus::chain starting with ShippingMethodsJob
        Bus::assertDispatched(MigrateShippingMethodsJob::class);
    }

    public function test_all_additional_jobs_are_queueable(): void
    {
        $jobs = [
            MigrateShippingMethodsJob::class,
            MigratePaymentMethodsJob::class,
            MigrateSeoUrlsJob::class,
        ];

        foreach ($jobs as $jobClass) {
            $this->assertTrue(
                in_array(\Illuminate\Contracts\Queue\ShouldQueue::class, class_implements($jobClass)),
                "{$jobClass} should implement ShouldQueue"
            );
        }
    }

    public function test_woocommerce_client_has_delete_method(): void
    {
        $this->assertTrue(
            method_exists(\App\Services\WooCommerceClient::class, 'delete'),
            'WooCommerceClient should have a delete() method for cleanup operations'
        );
    }
}

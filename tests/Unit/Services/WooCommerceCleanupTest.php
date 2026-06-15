<?php

namespace Tests\Unit\Services;

use App\Models\MigrationRun;
use App\Services\WooCommerceCleanup;
use App\Services\WooCommerceClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class WooCommerceCleanupTest extends TestCase
{
    use RefreshDatabase;

    private function migrationWith(array $settings): MigrationRun
    {
        return MigrationRun::create([
            'name' => 'Cleanup Test',
            'settings' => array_merge([
                'shopware' => [], 'woocommerce' => [], 'wordpress' => [],
            ], $settings),
            'status' => 'pending',
            'is_dry_run' => false,
        ]);
    }

    public function test_entities_for_omits_pages_when_cms_disabled(): void
    {
        $migration = $this->migrationWith([]);

        $entities = WooCommerceCleanup::entitiesFor($migration);

        $this->assertNotContains('pages', $entities, 'Pages cleanup must NOT run when CMS migration is off');
    }

    public function test_entities_for_includes_pages_when_cms_migrate_all(): void
    {
        $migration = $this->migrationWith([
            'cms_options' => ['migrate_all' => true],
        ]);

        $this->assertContains('pages', WooCommerceCleanup::entitiesFor($migration));
    }

    public function test_entities_for_includes_pages_when_cms_selected_ids(): void
    {
        $migration = $this->migrationWith([
            'cms_options' => ['selected_ids' => ['page-id-1']],
        ]);

        $this->assertContains('pages', WooCommerceCleanup::entitiesFor($migration));
    }

    public function test_entities_for_omits_pages_when_cms_selected_ids_empty(): void
    {
        $migration = $this->migrationWith([
            'cms_options' => ['selected_ids' => []],
        ]);

        $this->assertNotContains('pages', WooCommerceCleanup::entitiesFor($migration));
    }

    public function test_entities_for_omits_media_by_default(): void
    {
        $migration = $this->migrationWith([]);

        $this->assertNotContains('media', WooCommerceCleanup::entitiesFor($migration));
    }

    public function test_entities_for_includes_media_when_explicitly_enabled(): void
    {
        $migration = $this->migrationWith([
            'cleanup_options' => ['delete_media' => true],
        ]);

        $this->assertContains('media', WooCommerceCleanup::entitiesFor($migration));
    }

    public function test_batch_delete_all_counts_actual_deletions_not_chunk_size(): void
    {
        // Mig 65 inflation bug: WC's batch endpoint with force=true returns 200 even
        // when an ID is already gone, but only reports the actually-deleted ones in
        // response.delete[]. The counter must reflect that, not the chunk size.
        $woo = Mockery::mock(WooCommerceClient::class);
        $woo->shouldReceive('get')->once()
            ->andReturn([['id' => 10], ['id' => 11], ['id' => 12]]);
        $woo->shouldReceive('batchDelete')->once()
            ->with('orders', [10, 11, 12], [])
            ->andReturn(['delete' => [['id' => 10], ['id' => 12]]]);
        $woo->shouldReceive('get')->once()->andReturn([]);

        $cleanup = new WooCommerceCleanupHarness($woo);
        $result = $cleanup->run('orders', 'orders');

        $this->assertSame(2, $result['deleted'], 'Only IDs WC echoed back in delete[] count');
    }

    public function test_batch_delete_all_bails_when_same_ids_returned_twice(): void
    {
        // Hierarchy quirk: when WC reparents children after a parent delete, the
        // same IDs can keep coming back. We must not spin forever.
        $woo = Mockery::mock(WooCommerceClient::class);
        $woo->shouldReceive('get')->andReturn([['id' => 1], ['id' => 2]]);
        $woo->shouldReceive('batchDelete')->once()
            ->andReturn(['delete' => [['id' => 1], ['id' => 2]]]);

        $cleanup = new WooCommerceCleanupHarness($woo);
        $result = $cleanup->run('products/categories', 'categories');

        $this->assertSame(2, $result['deleted']);
    }

    public function test_entities_for_static_list_matches_known_set(): void
    {
        // Sanity-check: full static list still contains all cleanup steps in safe FK order
        // so other tooling that uses entities() (admin scripts, docs) keeps working.
        $static = WooCommerceCleanup::entities();
        $this->assertContains('orders', $static);
        $this->assertContains('products', $static);
        $this->assertContains('media', $static);
        $this->assertContains('pages', $static);
        // Order matters: orders must die before products
        $this->assertLessThan(array_search('products', $static), array_search('orders', $static));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}

/**
 * Exposes the protected batchDeleteAll helper for direct testing.
 */
class WooCommerceCleanupHarness extends WooCommerceCleanup
{
    public function run(string $endpoint, string $logName, ?callable $filter = null, array $extraQuery = []): array
    {
        return $this->batchDeleteAll($endpoint, $logName, $filter, $extraQuery);
    }
}

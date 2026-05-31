<?php

namespace Tests\Unit\Services;

use App\Models\MigrationRun;
use App\Services\WooCommerceCleanup;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}

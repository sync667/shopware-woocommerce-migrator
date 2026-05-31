<?php

namespace Tests\Feature\Controllers;

use App\Models\MigrationRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_renders_successfully(): void
    {
        $this->withoutVite();

        $response = $this->actsAsAuthenticated()->get('/');

        $response->assertStatus(200);
    }

    public function test_dashboard_includes_migrations(): void
    {
        $this->withoutVite();

        MigrationRun::create([
            'name' => 'Test Migration',
            'settings' => ['shopware' => [], 'woocommerce' => [], 'wordpress' => []],
            'status' => 'completed',
            'is_dry_run' => false,
        ]);

        $response = $this->actsAsAuthenticated()->get('/');

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->has('migrations', 1)
                ->where('migrations.0.name', 'Test Migration')
                ->where('migrations.0.status', 'completed')
            );
    }

    public function test_dashboard_limits_to_20_migrations(): void
    {
        $this->withoutVite();

        for ($i = 0; $i < 25; $i++) {
            MigrationRun::create([
                'name' => "Migration #{$i}",
                'settings' => ['shopware' => [], 'woocommerce' => [], 'wordpress' => []],
                'status' => 'completed',
                'is_dry_run' => false,
            ]);
        }

        $response = $this->actsAsAuthenticated()->get('/');

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->has('migrations', 20)
            );
    }

    public function test_dashboard_orders_by_created_at_desc(): void
    {
        $this->withoutVite();

        $this->travel(-2)->days();
        MigrationRun::create([
            'name' => 'Older',
            'settings' => ['shopware' => [], 'woocommerce' => [], 'wordpress' => []],
            'status' => 'completed',
            'is_dry_run' => false,
        ]);

        $this->travelBack();
        MigrationRun::create([
            'name' => 'Newer',
            'settings' => ['shopware' => [], 'woocommerce' => [], 'wordpress' => []],
            'status' => 'running',
            'is_dry_run' => false,
        ]);

        $response = $this->actsAsAuthenticated()->get('/');

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->where('migrations.0.name', 'Newer')
            );
    }

    public function test_dashboard_emits_enabled_optional_features(): void
    {
        $this->withoutVite();

        MigrationRun::create([
            'name' => 'All Optionals',
            'settings' => [
                'shopware' => [], 'woocommerce' => [], 'wordpress' => [],
                'cms_options' => ['migrate_all' => true],
                'stream_options' => ['migrate_streams' => true],
                'omnibus_options' => ['enabled' => true],
                'newsletter_options' => ['enabled' => true],
                'wishlist_options' => ['enabled' => false],
            ],
            'status' => 'completed',
            'is_dry_run' => false,
            'sync_mode' => 'delta',
        ]);

        $response = $this->actsAsAuthenticated()->get('/');

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->where('migrations.0.sync_mode', 'delta')
                ->where('migrations.0.options.cms_pages', true)
                ->where('migrations.0.options.product_streams', true)
                ->where('migrations.0.options.omnibus', true)
                ->where('migrations.0.options.newsletter', true)
                ->where('migrations.0.options.wishlist', false)
            );
    }

    public function test_dashboard_excludes_settings_from_migration_data(): void
    {
        $this->withoutVite();

        MigrationRun::create([
            'name' => 'Settings Test',
            'settings' => ['shopware' => ['db_password' => 'secret'], 'woocommerce' => [], 'wordpress' => []],
            'status' => 'completed',
            'is_dry_run' => false,
        ]);

        $response = $this->actsAsAuthenticated()->get('/');

        $response->assertStatus(200);

        $migrations = $response->original->getData()['page']['props']['migrations'];
        $firstMigration = $migrations[0] ?? null;
        $this->assertNotNull($firstMigration);
        $this->assertArrayNotHasKey('settings', $firstMigration);
    }

    public function test_settings_page_renders(): void
    {
        $this->withoutVite();

        $response = $this->actsAsAuthenticated()->get('/settings');

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page->component('Settings'));
    }
}

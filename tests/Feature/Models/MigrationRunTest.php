<?php

namespace Tests\Feature\Models;

use App\Models\MigrationEntity;
use App\Models\MigrationLog;
use App\Models\MigrationRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MigrationRunTest extends TestCase
{
    use RefreshDatabase;

    private function createMigration(array $overrides = []): MigrationRun
    {
        return MigrationRun::create(array_merge([
            'name' => 'Test Migration',
            'settings' => [
                'shopware' => ['db_host' => '127.0.0.1', 'db_port' => 3306, 'db_database' => 'sw', 'db_username' => 'root', 'db_password' => 'pass', 'language_id' => 'abc', 'live_version_id' => 'def', 'base_url' => 'https://shop.test'],
                'woocommerce' => ['base_url' => 'https://woo.test', 'consumer_key' => 'ck_test', 'consumer_secret' => 'cs_test'],
                'wordpress' => ['username' => 'admin', 'app_password' => 'pass'],
            ],
            'status' => 'pending',
            'is_dry_run' => false,
        ], $overrides));
    }

    public function test_creates_migration_run(): void
    {
        $migration = $this->createMigration();

        $this->assertDatabaseHas('migration_runs', [
            'id' => $migration->id,
            'name' => 'Test Migration',
            'status' => 'pending',
        ]);
    }

    public function test_settings_are_encrypted(): void
    {
        $migration = $this->createMigration();

        // Raw DB value should not be plain JSON
        $rawSettings = \DB::table('migration_runs')->where('id', $migration->id)->value('settings');
        $this->assertNotEquals(json_encode($migration->settings), $rawSettings);
    }

    public function test_settings_hidden_from_serialization(): void
    {
        $migration = $this->createMigration();
        $json = $migration->toArray();

        $this->assertArrayNotHasKey('settings', $json);
    }

    public function test_settings_accessible_via_model(): void
    {
        $migration = $this->createMigration();
        $fresh = MigrationRun::find($migration->id);

        $this->assertEquals('127.0.0.1', $fresh->setting('shopware.db_host'));
        $this->assertEquals('https://woo.test', $fresh->setting('woocommerce.base_url'));
        $this->assertEquals('admin', $fresh->setting('wordpress.username'));
    }

    public function test_mark_running(): void
    {
        $migration = $this->createMigration();

        $migration->markRunning();

        $this->assertEquals('running', $migration->fresh()->status);
        $this->assertNotNull($migration->fresh()->started_at);
    }

    public function test_mark_completed(): void
    {
        $migration = $this->createMigration(['status' => 'running']);

        $migration->markCompleted();

        $this->assertEquals('completed', $migration->fresh()->status);
        $this->assertNotNull($migration->fresh()->finished_at);
    }

    public function test_mark_failed(): void
    {
        $migration = $this->createMigration(['status' => 'running']);

        $migration->markFailed();

        $this->assertEquals('failed', $migration->fresh()->status);
        $this->assertNotNull($migration->fresh()->finished_at);
    }

    public function test_mark_paused(): void
    {
        $migration = $this->createMigration(['status' => 'running']);

        $migration->markPaused();

        $this->assertEquals('paused', $migration->fresh()->status);
    }

    public function test_has_entities_relationship(): void
    {
        $migration = $this->createMigration();
        MigrationEntity::create([
            'migration_id' => $migration->id,
            'entity_type' => 'product',
            'shopware_id' => 'abc123',
            'status' => 'success',
            'woo_id' => 42,
        ]);

        $this->assertCount(1, $migration->entities);
        $this->assertEquals('product', $migration->entities->first()->entity_type);
    }

    public function test_has_logs_relationship(): void
    {
        $migration = $this->createMigration();
        MigrationLog::create([
            'migration_id' => $migration->id,
            'entity_type' => 'product',
            'level' => 'info',
            'message' => 'Test log entry',
            'created_at' => now(),
        ]);

        $this->assertCount(1, $migration->logs);
        $this->assertEquals('Test log entry', $migration->logs->first()->message);
    }

    public function test_shopware_settings_accessor(): void
    {
        $migration = $this->createMigration();

        $settings = $migration->shopwareSettings();

        $this->assertEquals('127.0.0.1', $settings['db_host']);
        $this->assertEquals(3306, $settings['db_port']);
    }

    public function test_woocommerce_settings_accessor(): void
    {
        $migration = $this->createMigration();

        $settings = $migration->woocommerceSettings();

        $this->assertEquals('https://woo.test', $settings['base_url']);
        $this->assertEquals('ck_test', $settings['consumer_key']);
    }

    public function test_wordpress_settings_accessor(): void
    {
        $migration = $this->createMigration();

        $settings = $migration->wordpressSettings();

        $this->assertEquals('admin', $settings['username']);
    }

    public function test_is_dry_run_flag(): void
    {
        $migration = $this->createMigration(['is_dry_run' => true]);

        $this->assertTrue($migration->is_dry_run);
    }

    public function test_setting_with_default(): void
    {
        $migration = $this->createMigration();

        $this->assertEquals('fallback', $migration->setting('nonexistent.key', 'fallback'));
    }
}

<?php

namespace Tests\Feature\Controllers;

use App\Models\MigrationEntity;
use App\Models\MigrationLog;
use App\Models\MigrationRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MigrationControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Test Migration',
            'is_dry_run' => false,
            'settings' => [
                'shopware' => [
                    'db_host' => '127.0.0.1',
                    'db_port' => 3306,
                    'db_database' => 'shopware',
                    'db_username' => 'root',
                    'db_password' => 'password',
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
                    'app_password' => 'password123',
                ],
            ],
        ], $overrides);
    }

    // === Store endpoint tests ===

    public function test_store_creates_migration_and_dispatches_jobs(): void
    {
        Queue::fake();

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/migrations', $this->validPayload());

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'migration' => ['id', 'name', 'status', 'is_dry_run'],
            ]);

        $this->assertDatabaseHas('migration_runs', [
            'name' => 'Test Migration',
            'status' => 'running',
        ]);
    }

    public function test_store_validates_required_fields(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/migrations', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'settings']);
    }

    public function test_store_validates_nested_settings(): void
    {
        $payload = $this->validPayload();
        unset($payload['settings']['shopware']['db_host']);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/migrations', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['settings.shopware.db_host']);
    }

    public function test_store_validates_port_range(): void
    {
        $payload = $this->validPayload();
        $payload['settings']['shopware']['db_port'] = 99999;

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/migrations', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['settings.shopware.db_port']);
    }

    public function test_store_validates_url_format(): void
    {
        $payload = $this->validPayload();
        $payload['settings']['shopware']['base_url'] = 'not-a-url';

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/migrations', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['settings.shopware.base_url']);
    }

    public function test_store_rejects_http_shopware_base_url(): void
    {
        $payload = $this->validPayload();
        $payload['settings']['shopware']['base_url'] = 'http://insecure.test';

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/migrations', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['settings.shopware.base_url']);
    }

    public function test_store_rejects_http_woocommerce_base_url(): void
    {
        $payload = $this->validPayload();
        $payload['settings']['woocommerce']['base_url'] = 'http://insecure.test';

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/migrations', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['settings.woocommerce.base_url']);
    }

    public function test_store_creates_dry_run(): void
    {
        Queue::fake();

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/migrations', $this->validPayload(['is_dry_run' => true]));

        $response->assertStatus(201);
        $this->assertTrue($response->json('migration.is_dry_run'));
    }

    public function test_store_requires_authentication(): void
    {
        $response = $this->postJson('/api/migrations', $this->validPayload());

        $response->assertStatus(401);
    }

    // === Status endpoint tests ===

    public function test_status_returns_migration_info(): void
    {
        $migration = MigrationRun::create([
            'name' => 'Status Test',
            'settings' => $this->validPayload()['settings'],
            'status' => 'running',
            'is_dry_run' => false,
            'started_at' => now(),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/migrations/{$migration->id}/status");

        $response->assertOk()
            ->assertJsonStructure([
                'migration' => ['id', 'name', 'status', 'is_dry_run', 'started_at', 'finished_at', 'created_at'],
                'counts',
                'summary' => ['total', 'success', 'failed', 'pending', 'running', 'skipped'],
                'timing' => ['elapsed_seconds', 'eta_seconds'],
                'current_step',
                'last_activity',
                'recent_errors',
                'recent_warnings',
            ]);
    }

    public function test_status_includes_entity_counts(): void
    {
        $migration = MigrationRun::create([
            'name' => 'Count Test',
            'settings' => $this->validPayload()['settings'],
            'status' => 'running',
            'is_dry_run' => false,
        ]);

        MigrationEntity::create(['migration_id' => $migration->id, 'entity_type' => 'product', 'shopware_id' => 'p1', 'status' => 'success', 'woo_id' => 1]);
        MigrationEntity::create(['migration_id' => $migration->id, 'entity_type' => 'product', 'shopware_id' => 'p2', 'status' => 'success', 'woo_id' => 2]);
        MigrationEntity::create(['migration_id' => $migration->id, 'entity_type' => 'product', 'shopware_id' => 'p3', 'status' => 'failed', 'error_message' => 'API error']);
        MigrationEntity::create(['migration_id' => $migration->id, 'entity_type' => 'category', 'shopware_id' => 'c1', 'status' => 'success', 'woo_id' => 10]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/migrations/{$migration->id}/status");

        $response->assertOk();
        $counts = $response->json('counts');
        $this->assertEquals(2, $counts['product']['success']);
        $this->assertEquals(1, $counts['product']['failed']);
        $this->assertEquals(1, $counts['category']['success']);
    }

    public function test_status_includes_recent_errors(): void
    {
        $migration = MigrationRun::create([
            'name' => 'Error Test',
            'settings' => $this->validPayload()['settings'],
            'status' => 'running',
            'is_dry_run' => false,
        ]);

        MigrationLog::create([
            'migration_id' => $migration->id,
            'entity_type' => 'product',
            'shopware_id' => 'p1',
            'level' => 'error',
            'message' => 'API connection failed',
            'created_at' => now(),
        ]);

        MigrationLog::create([
            'migration_id' => $migration->id,
            'entity_type' => 'product',
            'level' => 'info',
            'message' => 'This should not appear',
            'created_at' => now(),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/migrations/{$migration->id}/status");

        $response->assertOk();
        $errors = $response->json('recent_errors');
        $this->assertCount(1, $errors);
        $this->assertEquals('API connection failed', $errors[0]['message']);
    }

    public function test_status_requires_authentication(): void
    {
        $migration = MigrationRun::create([
            'name' => 'Auth Test',
            'settings' => $this->validPayload()['settings'],
            'status' => 'running',
            'is_dry_run' => false,
        ]);

        $response = $this->getJson("/api/migrations/{$migration->id}/status");

        $response->assertStatus(401);
    }

    public function test_status_returns_summary_stats(): void
    {
        $migration = MigrationRun::create([
            'name' => 'Summary Test',
            'settings' => $this->validPayload()['settings'],
            'status' => 'running',
            'is_dry_run' => false,
            'started_at' => now()->subMinutes(5),
        ]);

        MigrationEntity::create(['migration_id' => $migration->id, 'entity_type' => 'product', 'shopware_id' => 'p1', 'status' => 'success', 'woo_id' => 1]);
        MigrationEntity::create(['migration_id' => $migration->id, 'entity_type' => 'product', 'shopware_id' => 'p2', 'status' => 'success', 'woo_id' => 2]);
        MigrationEntity::create(['migration_id' => $migration->id, 'entity_type' => 'product', 'shopware_id' => 'p3', 'status' => 'failed', 'error_message' => 'Error']);
        MigrationEntity::create(['migration_id' => $migration->id, 'entity_type' => 'category', 'shopware_id' => 'c1', 'status' => 'pending']);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/migrations/{$migration->id}/status");

        $response->assertOk();
        $summary = $response->json('summary');
        $this->assertEquals(4, $summary['total']);
        $this->assertEquals(2, $summary['success']);
        $this->assertEquals(1, $summary['failed']);
        $this->assertEquals(1, $summary['pending']);
    }

    public function test_status_returns_elapsed_time(): void
    {
        $migration = MigrationRun::create([
            'name' => 'Timing Test',
            'settings' => $this->validPayload()['settings'],
            'status' => 'running',
            'is_dry_run' => false,
            'started_at' => now()->subMinutes(2),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/migrations/{$migration->id}/status");

        $response->assertOk();
        $timing = $response->json('timing');
        $this->assertNotNull($timing['elapsed_seconds']);
        $this->assertGreaterThanOrEqual(120, $timing['elapsed_seconds']);
    }

    public function test_status_returns_warnings(): void
    {
        $migration = MigrationRun::create([
            'name' => 'Warning Test',
            'settings' => $this->validPayload()['settings'],
            'status' => 'running',
            'is_dry_run' => false,
        ]);

        MigrationLog::create([
            'migration_id' => $migration->id,
            'entity_type' => 'review',
            'level' => 'warning',
            'message' => 'Product not yet migrated, skipping review',
            'created_at' => now(),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/migrations/{$migration->id}/status");

        $response->assertOk();
        $warnings = $response->json('recent_warnings');
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('skipping review', $warnings[0]['message']);
    }

    public function test_status_returns_last_activity(): void
    {
        $migration = MigrationRun::create([
            'name' => 'Activity Test',
            'settings' => $this->validPayload()['settings'],
            'status' => 'running',
            'is_dry_run' => false,
        ]);

        MigrationLog::create([
            'migration_id' => $migration->id,
            'entity_type' => 'product',
            'level' => 'info',
            'message' => 'Migrated product SKU-001',
            'created_at' => now(),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/migrations/{$migration->id}/status");

        $response->assertOk();
        $lastActivity = $response->json('last_activity');
        $this->assertNotNull($lastActivity);
        $this->assertEquals('product', $lastActivity['entity_type']);
        $this->assertEquals('info', $lastActivity['level']);
    }

    // === Pause/Resume/Cancel tests ===

    public function test_pause_marks_migration_as_paused(): void
    {
        $migration = MigrationRun::create([
            'name' => 'Pause Test',
            'settings' => $this->validPayload()['settings'],
            'status' => 'running',
            'is_dry_run' => false,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/migrations/{$migration->id}/pause");

        $response->assertOk()
            ->assertJson(['message' => 'Migration paused']);

        $this->assertEquals('paused', $migration->fresh()->status);
    }

    public function test_resume_marks_migration_as_running(): void
    {
        $migration = MigrationRun::create([
            'name' => 'Resume Test',
            'settings' => $this->validPayload()['settings'],
            'status' => 'paused',
            'is_dry_run' => false,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/migrations/{$migration->id}/resume");

        $response->assertOk()
            ->assertJson(['message' => 'Migration resumed']);

        $this->assertEquals('running', $migration->fresh()->status);
    }

    public function test_cancel_marks_migration_as_failed(): void
    {
        $migration = MigrationRun::create([
            'name' => 'Cancel Test',
            'settings' => $this->validPayload()['settings'],
            'status' => 'running',
            'is_dry_run' => false,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/migrations/{$migration->id}/cancel");

        $response->assertOk()
            ->assertJson(['message' => 'Migration cancelled']);

        $this->assertEquals('failed', $migration->fresh()->status);
        $this->assertNotNull($migration->fresh()->finished_at);
    }

    public function test_pause_requires_authentication(): void
    {
        $migration = MigrationRun::create([
            'name' => 'Auth Pause',
            'settings' => $this->validPayload()['settings'],
            'status' => 'running',
            'is_dry_run' => false,
        ]);

        $this->postJson("/api/migrations/{$migration->id}/pause")->assertStatus(401);
        $this->postJson("/api/migrations/{$migration->id}/resume")->assertStatus(401);
        $this->postJson("/api/migrations/{$migration->id}/cancel")->assertStatus(401);
    }

    // === Ping endpoint tests ===

    public function test_ping_shopware_validates_inputs(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/shopware/ping', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['db_host', 'db_port', 'db_database', 'db_username', 'db_password']);
    }

    public function test_ping_shopware_validates_port_range(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/shopware/ping', [
                'db_host' => '127.0.0.1',
                'db_port' => 99999,
                'db_database' => 'test',
                'db_username' => 'root',
                'db_password' => 'pass',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['db_port']);
    }

    public function test_ping_woocommerce_validates_inputs(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/woocommerce/ping', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['base_url', 'consumer_key', 'consumer_secret']);
    }

    public function test_ping_woocommerce_validates_url(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/woocommerce/ping', [
                'base_url' => 'not-a-url',
                'consumer_key' => 'ck_test',
                'consumer_secret' => 'cs_test',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['base_url']);
    }

    public function test_ping_woocommerce_rejects_http_url(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/woocommerce/ping', [
                'base_url' => 'http://insecure.test',
                'consumer_key' => 'ck_test',
                'consumer_secret' => 'cs_test',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['base_url']);
    }

    public function test_ping_requires_authentication(): void
    {
        $this->postJson('/api/shopware/ping', ['db_host' => '127.0.0.1'])->assertStatus(401);
        $this->postJson('/api/woocommerce/ping', ['base_url' => 'https://test.com'])->assertStatus(401);
    }
}

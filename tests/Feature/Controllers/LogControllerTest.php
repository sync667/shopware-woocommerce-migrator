<?php

namespace Tests\Feature\Controllers;

use App\Models\MigrationLog;
use App\Models\MigrationRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private MigrationRun $migration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->migration = MigrationRun::create([
            'name' => 'Log Test',
            'settings' => [
                'shopware' => ['db_host' => '127.0.0.1', 'db_port' => 3306, 'db_database' => 'sw', 'db_username' => 'root', 'db_password' => 'pass', 'language_id' => 'abc', 'live_version_id' => 'def', 'base_url' => 'https://shop.test'],
                'woocommerce' => ['base_url' => 'https://woo.test', 'consumer_key' => 'ck', 'consumer_secret' => 'cs'],
                'wordpress' => ['username' => 'admin', 'app_password' => 'pass'],
            ],
            'status' => 'running',
            'is_dry_run' => false,
        ]);
    }

    public function test_returns_paginated_logs(): void
    {
        for ($i = 0; $i < 5; $i++) {
            MigrationLog::create([
                'migration_id' => $this->migration->id,
                'entity_type' => 'product',
                'level' => 'info',
                'message' => "Log message {$i}",
                'created_at' => now()->subMinutes($i),
            ]);
        }

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/migrations/{$this->migration->id}/logs");

        $response->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonStructure([
                'data' => [['id', 'migration_id', 'entity_type', 'level', 'message', 'created_at']],
                'current_page',
                'last_page',
                'total',
            ]);
    }

    public function test_filters_by_entity_type(): void
    {
        MigrationLog::create(['migration_id' => $this->migration->id, 'entity_type' => 'product', 'level' => 'info', 'message' => 'Product log', 'created_at' => now()]);
        MigrationLog::create(['migration_id' => $this->migration->id, 'entity_type' => 'category', 'level' => 'info', 'message' => 'Category log', 'created_at' => now()]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/migrations/{$this->migration->id}/logs?entity_type=product");

        $response->assertOk()
            ->assertJsonCount(1, 'data');

        $this->assertEquals('Product log', $response->json('data.0.message'));
    }

    public function test_filters_by_level(): void
    {
        MigrationLog::create(['migration_id' => $this->migration->id, 'entity_type' => 'product', 'level' => 'info', 'message' => 'Info log', 'created_at' => now()]);
        MigrationLog::create(['migration_id' => $this->migration->id, 'entity_type' => 'product', 'level' => 'error', 'message' => 'Error log', 'created_at' => now()]);
        MigrationLog::create(['migration_id' => $this->migration->id, 'entity_type' => 'product', 'level' => 'warning', 'message' => 'Warning log', 'created_at' => now()]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/migrations/{$this->migration->id}/logs?level=error");

        $response->assertOk()
            ->assertJsonCount(1, 'data');

        $this->assertEquals('Error log', $response->json('data.0.message'));
    }

    public function test_filters_by_search_term(): void
    {
        MigrationLog::create(['migration_id' => $this->migration->id, 'entity_type' => 'product', 'level' => 'error', 'message' => 'API connection timeout', 'created_at' => now()]);
        MigrationLog::create(['migration_id' => $this->migration->id, 'entity_type' => 'product', 'level' => 'info', 'message' => 'Product migrated OK', 'created_at' => now()]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/migrations/{$this->migration->id}/logs?search=timeout");

        $response->assertOk()
            ->assertJsonCount(1, 'data');

        $this->assertStringContainsString('timeout', $response->json('data.0.message'));
    }

    public function test_respects_per_page(): void
    {
        for ($i = 0; $i < 10; $i++) {
            MigrationLog::create([
                'migration_id' => $this->migration->id,
                'entity_type' => 'product',
                'level' => 'info',
                'message' => "Log {$i}",
                'created_at' => now()->subMinutes($i),
            ]);
        }

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/migrations/{$this->migration->id}/logs?per_page=3");

        $response->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJson(['last_page' => 4, 'total' => 10]);
    }

    public function test_orders_by_created_at_desc(): void
    {
        MigrationLog::create(['migration_id' => $this->migration->id, 'entity_type' => 'product', 'level' => 'info', 'message' => 'First', 'created_at' => now()->subMinutes(5)]);
        MigrationLog::create(['migration_id' => $this->migration->id, 'entity_type' => 'product', 'level' => 'info', 'message' => 'Latest', 'created_at' => now()]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/migrations/{$this->migration->id}/logs");

        $response->assertOk();
        $this->assertEquals('Latest', $response->json('data.0.message'));
    }

    public function test_combines_multiple_filters(): void
    {
        MigrationLog::create(['migration_id' => $this->migration->id, 'entity_type' => 'product', 'level' => 'error', 'message' => 'Product API error', 'created_at' => now()]);
        MigrationLog::create(['migration_id' => $this->migration->id, 'entity_type' => 'product', 'level' => 'info', 'message' => 'Product OK', 'created_at' => now()]);
        MigrationLog::create(['migration_id' => $this->migration->id, 'entity_type' => 'category', 'level' => 'error', 'message' => 'Category API error', 'created_at' => now()]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/migrations/{$this->migration->id}/logs?entity_type=product&level=error");

        $response->assertOk()
            ->assertJsonCount(1, 'data');

        $this->assertEquals('Product API error', $response->json('data.0.message'));
    }

    public function test_returns_empty_for_no_logs(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/migrations/{$this->migration->id}/logs");

        $response->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_requires_authentication(): void
    {
        $response = $this->getJson("/api/migrations/{$this->migration->id}/logs");

        $response->assertStatus(401);
    }
}

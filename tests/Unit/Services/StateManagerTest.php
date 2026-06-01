<?php

namespace Tests\Unit\Services;

use App\Models\MigrationEntity;
use App\Models\MigrationRun;
use App\Services\StateManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StateManagerTest extends TestCase
{
    use RefreshDatabase;

    private StateManager $stateManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stateManager = new StateManager;
    }

    public function test_set_and_get(): void
    {
        $migration = MigrationRun::create([
            'name' => 'Test',
            'settings' => ['shopware' => [], 'woocommerce' => [], 'wordpress' => []],
            'status' => 'running',
        ]);

        $this->stateManager->set('product', 'abc123', 42, $migration->id);

        $result = $this->stateManager->get('product', 'abc123', $migration->id);
        $this->assertEquals(42, $result);
    }

    public function test_already_migrated(): void
    {
        $migration = MigrationRun::create([
            'name' => 'Test',
            'settings' => ['shopware' => [], 'woocommerce' => [], 'wordpress' => []],
            'status' => 'running',
        ]);

        $this->assertFalse($this->stateManager->alreadyMigrated('product', 'abc123', $migration->id));

        $this->stateManager->set('product', 'abc123', 42, $migration->id);

        $this->assertTrue($this->stateManager->alreadyMigrated('product', 'abc123', $migration->id));
    }

    public function test_already_migrated_counts_skipped_for_resume(): void
    {
        // Dry runs mark every processed row as `skipped`. A killed-and-retried job
        // must see those rows as already-done so it resumes where it left off
        // instead of restarting from row 0.
        $migration = MigrationRun::create([
            'name' => 'Resume Test',
            'settings' => ['shopware' => [], 'woocommerce' => [], 'wordpress' => []],
            'status' => 'running',
        ]);

        $this->stateManager->markSkipped('seo_url', 'urlA', $migration->id, ['skip_reason' => 'dry_run']);
        $this->assertTrue($this->stateManager->alreadyMigrated('seo_url', 'urlA', $migration->id));
    }

    public function test_already_migrated_excludes_pending_and_failed(): void
    {
        $migration = MigrationRun::create([
            'name' => 'Pending/Failed Test',
            'settings' => ['shopware' => [], 'woocommerce' => [], 'wordpress' => []],
            'status' => 'running',
        ]);

        // pending = will run; failed = should retry on next attempt.
        $this->stateManager->markPending('product', 'pending-id', $migration->id);
        $this->stateManager->markFailed('product', 'failed-id', $migration->id, 'simulated');

        $this->assertFalse($this->stateManager->alreadyMigrated('product', 'pending-id', $migration->id));
        $this->assertFalse($this->stateManager->alreadyMigrated('product', 'failed-id', $migration->id));
    }

    public function test_get_map(): void
    {
        $migration = MigrationRun::create([
            'name' => 'Test',
            'settings' => ['shopware' => [], 'woocommerce' => [], 'wordpress' => []],
            'status' => 'running',
        ]);

        $this->stateManager->set('product', 'aaa', 1, $migration->id);
        $this->stateManager->set('product', 'bbb', 2, $migration->id);
        $this->stateManager->set('category', 'ccc', 3, $migration->id);

        $map = $this->stateManager->getMap('product', $migration->id);

        $this->assertCount(2, $map);
        $this->assertEquals(1, $map['aaa']);
        $this->assertEquals(2, $map['bbb']);
    }

    public function test_mark_failed(): void
    {
        $migration = MigrationRun::create([
            'name' => 'Test',
            'settings' => ['shopware' => [], 'woocommerce' => [], 'wordpress' => []],
            'status' => 'running',
        ]);

        $this->stateManager->markFailed('product', 'abc123', $migration->id, 'Connection timeout');

        $entity = MigrationEntity::where('migration_id', $migration->id)
            ->where('shopware_id', 'abc123')
            ->first();

        $this->assertEquals('failed', $entity->status);
        $this->assertEquals('Connection timeout', $entity->error_message);
    }

    public function test_migrations_are_isolated(): void
    {
        $m1 = MigrationRun::create([
            'name' => 'Migration 1',
            'settings' => ['shopware' => [], 'woocommerce' => [], 'wordpress' => []],
            'status' => 'running',
        ]);
        $m2 = MigrationRun::create([
            'name' => 'Migration 2',
            'settings' => ['shopware' => [], 'woocommerce' => [], 'wordpress' => []],
            'status' => 'running',
        ]);

        $this->stateManager->set('product', 'abc', 10, $m1->id);
        $this->stateManager->set('product', 'abc', 20, $m2->id);

        $this->assertEquals(10, $this->stateManager->get('product', 'abc', $m1->id));
        $this->assertEquals(20, $this->stateManager->get('product', 'abc', $m2->id));
    }

    public function test_mark_skipped(): void
    {
        $migration = MigrationRun::create([
            'name' => 'Test',
            'settings' => ['shopware' => [], 'woocommerce' => [], 'wordpress' => []],
            'status' => 'running',
        ]);

        $this->stateManager->markSkipped('product', 'abc123', $migration->id, ['name' => 'Test Product']);

        $entity = MigrationEntity::where('migration_id', $migration->id)
            ->where('shopware_id', 'abc123')
            ->first();

        $this->assertEquals('skipped', $entity->status);
        $this->assertNull($entity->woo_id);
        $this->assertEquals(['name' => 'Test Product'], $entity->payload);
    }

    public function test_set_with_payload(): void
    {
        $migration = MigrationRun::create([
            'name' => 'Test',
            'settings' => ['shopware' => [], 'woocommerce' => [], 'wordpress' => []],
            'status' => 'running',
        ]);

        $this->stateManager->set('product', 'abc123', 42, $migration->id, ['method_id' => 'flat_rate']);

        $entity = MigrationEntity::where('migration_id', $migration->id)
            ->where('shopware_id', 'abc123')
            ->first();

        $this->assertEquals('success', $entity->status);
        $this->assertEquals(42, $entity->woo_id);
        $this->assertEquals(['method_id' => 'flat_rate'], $entity->payload);
    }
}

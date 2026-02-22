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
}

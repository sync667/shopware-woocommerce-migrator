<?php

namespace Tests\Feature\Jobs;

use App\Jobs\LinkCrossSellBatchJob;
use App\Jobs\LinkCrossSellsJob;
use App\Models\MigrationEntity;
use App\Models\MigrationRun;
use Illuminate\Bus\PendingBatch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class LinkCrossSellsJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_implements_should_queue_on_migration_queue(): void
    {
        $this->assertTrue(in_array(ShouldQueue::class, class_implements(LinkCrossSellsJob::class)));

        $job = new LinkCrossSellsJob(1);
        $this->assertSame('migration', $job->queue);
    }

    public function test_categorize_splits_by_group_name_case_insensitively(): void
    {
        $rows = [
            (object) ['target_product_id' => 'sw-A', 'group_name' => 'ZOBACZ RÓWNIEŻ', 'type' => 'productStream'],
            (object) ['target_product_id' => 'sw-B', 'group_name' => 'zobacz również',  'type' => 'productList'],
            (object) ['target_product_id' => 'sw-C', 'group_name' => 'DOBIERZ AKCESORIA!', 'type' => 'productStream'],
            (object) ['target_product_id' => 'sw-D', 'group_name' => 'DEDYKOWANE PRODUKTY', 'type' => 'productList'],
        ];
        $migrated = ['sw-A' => 100, 'sw-B' => 101, 'sw-C' => 102, 'sw-D' => 103];

        [$upsell, $cross, $missed] = LinkCrossSellsJob::categorizeCrossSells(
            $rows, $migrated, ['zobacz również']
        );

        $this->assertSame([100, 101], $upsell);
        $this->assertSame([102, 103], $cross);
        $this->assertSame(0, $missed);
    }

    public function test_categorize_drops_unresolved_target_refs(): void
    {
        $rows = [
            (object) ['target_product_id' => 'sw-A', 'group_name' => 'whatever', 'type' => 'productList'],
            (object) ['target_product_id' => 'sw-MISSING', 'group_name' => 'whatever', 'type' => 'productList'],
        ];
        $migrated = ['sw-A' => 100];

        [$upsell, $cross, $missed] = LinkCrossSellsJob::categorizeCrossSells($rows, $migrated, []);

        $this->assertSame([], $upsell);
        $this->assertSame([100], $cross);
        $this->assertSame(1, $missed);
    }

    public function test_categorize_dedupes_repeated_target_ids(): void
    {
        // Same product can appear in multiple groups (mig57 has parents with 2+ groups).
        // Deduping prevents WC from receiving duplicate ids in the final list.
        $rows = [
            (object) ['target_product_id' => 'sw-A', 'group_name' => 'a', 'type' => 'productList'],
            (object) ['target_product_id' => 'sw-A', 'group_name' => 'b', 'type' => 'productStream'],
        ];
        [$upsell, $cross, $missed] = LinkCrossSellsJob::categorizeCrossSells(
            $rows, ['sw-A' => 100], []
        );

        $this->assertSame([], $upsell);
        $this->assertSame([100], $cross);
        $this->assertSame(0, $missed);
    }

    public function test_categorize_empty_input_yields_empty_output(): void
    {
        [$upsell, $cross, $missed] = LinkCrossSellsJob::categorizeCrossSells([], [], []);
        $this->assertSame([], $upsell);
        $this->assertSame([], $cross);
        $this->assertSame(0, $missed);
    }

    public function test_batch_job_implements_should_queue_on_migration_queue(): void
    {
        $this->assertTrue(in_array(ShouldQueue::class, class_implements(\App\Jobs\LinkCrossSellBatchJob::class)));

        $job = new \App\Jobs\LinkCrossSellBatchJob(1, ['sw-A']);
        $this->assertSame('migration', $job->queue);
    }

    public function test_chunk_size_constant_matches_dispatcher_default(): void
    {
        $this->assertSame(100, LinkCrossSellsJob::CHUNK_SIZE);
    }

    public function test_dispatcher_chunks_migrated_products_into_bus_batch(): void
    {
        $migration = MigrationRun::create([
            'name' => 'test',
            'settings' => ['shopware' => ['upsell_group_names' => []]],
            'status' => 'running',
            'is_dry_run' => false,
        ]);

        for ($i = 0; $i < 250; $i++) {
            MigrationEntity::create([
                'migration_id' => $migration->id,
                'entity_type' => 'product',
                'shopware_id' => 'sw-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'woo_id' => 1000 + $i,
                'status' => 'success',
            ]);
        }

        Bus::fake();

        (new LinkCrossSellsJob($migration->id))->handle();

        Bus::assertBatched(function (PendingBatch $batch) {
            return $batch->jobs->count() === 3
                && $batch->jobs->every(fn ($j) => $j instanceof LinkCrossSellBatchJob);
        });
    }

    public function test_dispatcher_skips_unmigrated_products(): void
    {
        $migration = MigrationRun::create([
            'name' => 'test',
            'settings' => ['shopware' => ['upsell_group_names' => []]],
            'status' => 'running',
            'is_dry_run' => false,
        ]);
        MigrationEntity::create([
            'migration_id' => $migration->id,
            'entity_type' => 'product',
            'shopware_id' => 'sw-FAILED',
            'woo_id' => null,
            'status' => 'failed',
        ]);

        Bus::fake();

        (new LinkCrossSellsJob($migration->id))->handle();

        Bus::assertNotDispatched(LinkCrossSellBatchJob::class);
        Bus::assertDispatched(\App\Jobs\MigrateCustomersJob::class);
    }
}

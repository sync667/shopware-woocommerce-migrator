<?php

namespace Tests\Feature\Jobs;

use App\Jobs\LinkCrossSellsJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use PHPUnit\Framework\TestCase;

class LinkCrossSellsJobTest extends TestCase
{
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
}

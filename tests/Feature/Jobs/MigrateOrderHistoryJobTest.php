<?php

namespace Tests\Feature\Jobs;

use App\Jobs\MigrateOrderHistoryJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use PHPUnit\Framework\TestCase;

class MigrateOrderHistoryJobTest extends TestCase
{
    public function test_implements_should_queue_on_migration_queue(): void
    {
        $this->assertTrue(in_array(ShouldQueue::class, class_implements(MigrateOrderHistoryJob::class)));
        $job = new MigrateOrderHistoryJob(1);
        $this->assertSame('migration', $job->queue);
    }

    public function test_build_notes_maps_state_transitions_to_wc_order_notes(): void
    {
        $history = [
            'sw-A' => [
                (object) ['from_state' => 'open', 'to_state' => 'in_progress', 'transition_at' => '2024-01-01 10:00:00'],
                (object) ['from_state' => 'in_progress', 'to_state' => 'completed', 'transition_at' => '2024-01-02 14:30:00'],
            ],
            'sw-B' => [
                (object) ['from_state' => 'open', 'to_state' => 'cancelled', 'transition_at' => '2024-01-03 09:00:00'],
            ],
        ];
        $orderMap = ['sw-A' => 21034, 'sw-B' => 21035];

        $notes = MigrateOrderHistoryJob::buildNotes($history, $orderMap);

        $this->assertCount(3, $notes);

        $this->assertSame(21034, $notes[0]['order_id']);
        $this->assertSame('Shopware status: open → in_progress', $notes[0]['content']);
        $this->assertSame('2024-01-01 10:00:00', $notes[0]['transition_at']);

        $this->assertSame(21034, $notes[1]['order_id']);
        $this->assertSame('Shopware status: in_progress → completed', $notes[1]['content']);

        $this->assertSame(21035, $notes[2]['order_id']);
        $this->assertSame('Shopware status: open → cancelled', $notes[2]['content']);
    }

    public function test_build_notes_skips_unmigrated_orders(): void
    {
        $history = [
            'sw-MIGRATED' => [
                (object) ['from_state' => 'open', 'to_state' => 'completed', 'transition_at' => '2024-01-01 10:00:00'],
            ],
            'sw-NOT-MIGRATED' => [
                (object) ['from_state' => 'open', 'to_state' => 'completed', 'transition_at' => '2024-01-02 10:00:00'],
            ],
        ];
        $orderMap = ['sw-MIGRATED' => 100];

        $notes = MigrateOrderHistoryJob::buildNotes($history, $orderMap);

        $this->assertCount(1, $notes);
        $this->assertSame(100, $notes[0]['order_id']);
    }

    public function test_build_notes_skips_rows_with_empty_states_or_timestamp(): void
    {
        $history = [
            'sw-A' => [
                (object) ['from_state' => '', 'to_state' => 'completed', 'transition_at' => '2024-01-01 10:00:00'],
                (object) ['from_state' => 'open', 'to_state' => '', 'transition_at' => '2024-01-01 10:00:00'],
                (object) ['from_state' => 'open', 'to_state' => 'completed', 'transition_at' => ''],
                (object) ['from_state' => 'open', 'to_state' => 'in_progress', 'transition_at' => '2024-01-01 10:00:00'],
            ],
        ];
        $notes = MigrateOrderHistoryJob::buildNotes($history, ['sw-A' => 100]);

        $this->assertCount(1, $notes);
        $this->assertSame('Shopware status: open → in_progress', $notes[0]['content']);
    }

    public function test_build_notes_empty_input_yields_empty_output(): void
    {
        $this->assertSame([], MigrateOrderHistoryJob::buildNotes([], []));
        $this->assertSame([], MigrateOrderHistoryJob::buildNotes(['sw-A' => []], ['sw-A' => 100]));
    }
}

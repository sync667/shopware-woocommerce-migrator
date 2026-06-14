<?php

namespace Tests\Feature\Jobs;

use App\Jobs\MigrateProductAttributesJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use PHPUnit\Framework\TestCase;

class MigrateProductAttributesJobTest extends TestCase
{
    public function test_implements_should_queue_on_migration_queue(): void
    {
        $this->assertTrue(in_array(ShouldQueue::class, class_implements(MigrateProductAttributesJob::class)));
        $job = new MigrateProductAttributesJob(1);
        $this->assertSame('migration', $job->queue);
    }

    public function test_has_long_timeout_so_it_can_register_hundreds_of_attributes(): void
    {
        $job = new MigrateProductAttributesJob(1);
        $this->assertGreaterThanOrEqual(600, $job->timeout);
    }
}

<?php

namespace Tests\Feature\Jobs;

use App\Jobs\MigrateCategoriesJob;
use App\Jobs\MigrateManufacturersJob;
use App\Jobs\MigrateProductAttributesJob;
use App\Jobs\MigrateTaxesJob;
use App\Jobs\PrepareCatalogJob;
use Illuminate\Bus\PendingBatch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class PrepareCatalogJobTest extends TestCase
{
    public function test_dispatches_bus_batch_with_all_four_prep_jobs(): void
    {
        Bus::fake();

        (new PrepareCatalogJob(42))->handle();

        Bus::assertBatched(function (PendingBatch $batch) {
            $jobs = $batch->jobs instanceof Collection
                ? $batch->jobs->all()
                : (array) $batch->jobs;
            $classes = array_map(fn ($j) => get_class($j), $jobs);

            return $batch->name === 'prepare-catalog-42'
                && in_array(MigrateManufacturersJob::class, $classes, true)
                && in_array(MigrateTaxesJob::class, $classes, true)
                && in_array(MigrateCategoriesJob::class, $classes, true)
                && in_array(MigrateProductAttributesJob::class, $classes, true);
        });
    }

    public function test_runs_on_migration_queue(): void
    {
        $job = new PrepareCatalogJob(1);

        $this->assertSame('migration', $job->queue);
    }

    public function test_short_timeout_since_handle_only_dispatches(): void
    {
        $job = new PrepareCatalogJob(1);

        $this->assertLessThanOrEqual(120, $job->timeout, 'handle() only dispatches the batch — should not need a long timeout');
    }
}

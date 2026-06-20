<?php

namespace Tests\Feature\Jobs;

use App\Jobs\MigrateProductBatchJob;
use Tests\TestCase;

class MigrateProductBatchJobSizeChartTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['migration.size_chart.custom_field' => 'product_additional_fields_size']);
    }

    public function test_extracts_size_chart_media_id_from_custom_fields(): void
    {
        $json = json_encode([
            'product_additional_fields_size' => 'cf5220bffd1d4512add78744271b4db6',
            'other' => 'x',
        ]);

        $this->assertSame(
            'cf5220bffd1d4512add78744271b4db6',
            MigrateProductBatchJob::sizeChartMediaId($json),
        );
    }

    public function test_returns_null_when_field_absent(): void
    {
        $json = json_encode(['other' => 'x']);
        $this->assertNull(MigrateProductBatchJob::sizeChartMediaId($json));
    }

    public function test_returns_null_for_empty_or_invalid_input(): void
    {
        $this->assertNull(MigrateProductBatchJob::sizeChartMediaId(null));
        $this->assertNull(MigrateProductBatchJob::sizeChartMediaId(''));
        $this->assertNull(MigrateProductBatchJob::sizeChartMediaId('{not json'));
    }

    public function test_returns_null_when_field_value_empty_string(): void
    {
        $json = json_encode(['product_additional_fields_size' => '']);
        $this->assertNull(MigrateProductBatchJob::sizeChartMediaId($json));
    }
}

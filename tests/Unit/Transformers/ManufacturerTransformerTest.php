<?php

namespace Tests\Unit\Transformers;

use App\Shopware\Transformers\ManufacturerTransformer;
use PHPUnit\Framework\TestCase;

class ManufacturerTransformerTest extends TestCase
{
    private ManufacturerTransformer $transformer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transformer = new ManufacturerTransformer;
    }

    public function test_transforms_manufacturer(): void
    {
        $manufacturer = (object) [
            'id' => 'abc123',
            'name' => 'Nike',
        ];

        $result = $this->transformer->transform($manufacturer);

        $this->assertEquals('abc123', $result['shopware_id']);
        $this->assertEquals('Nike', $result['name']);
    }

    public function test_handles_empty_name(): void
    {
        $manufacturer = (object) [
            'id' => 'def456',
            'name' => '',
        ];

        $result = $this->transformer->transform($manufacturer);

        $this->assertEquals('Unknown', $result['name']);
    }

    public function test_handles_null_name(): void
    {
        $manufacturer = (object) [
            'id' => 'ghi789',
            'name' => null,
        ];

        $result = $this->transformer->transform($manufacturer);

        $this->assertEquals('Unknown', $result['name']);
    }
}

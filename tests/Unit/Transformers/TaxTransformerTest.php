<?php

namespace Tests\Unit\Transformers;

use App\Shopware\Transformers\TaxTransformer;
use PHPUnit\Framework\TestCase;

class TaxTransformerTest extends TestCase
{
    private TaxTransformer $transformer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transformer = new TaxTransformer;
    }

    public function test_transforms_tax(): void
    {
        $tax = (object) [
            'id' => 'abc123',
            'name' => 'Standard Rate',
            'tax_rate' => 23.0,
        ];

        $result = $this->transformer->transform($tax);

        $this->assertEquals('abc123', $result['shopware_id']);
        $this->assertEquals('Standard Rate', $result['name']);
        $this->assertEquals('standard-rate', $result['slug']);
        $this->assertEquals(23.0, $result['rate']);
    }

    public function test_transforms_tax_slug_with_special_characters(): void
    {
        $tax = (object) [
            'id' => 'def456',
            'name' => 'Reduced Rate (7%)',
            'tax_rate' => 7.0,
        ];

        $result = $this->transformer->transform($tax);

        $this->assertEquals('reduced-rate-7', $result['slug']);
    }

    public function test_transforms_tax_rule(): void
    {
        $rule = (object) [
            'country_iso' => 'DE',
            'rate' => 19.0,
        ];

        $result = $this->transformer->transformRule($rule, 'Standard Rate');

        $this->assertEquals('DE', $result['country']);
        $this->assertEquals('19', $result['rate']);
        $this->assertEquals('Standard Rate', $result['name']);
        $this->assertEquals('Standard Rate', $result['class']);
        $this->assertEquals(1, $result['priority']);
        $this->assertFalse($result['compound']);
        $this->assertTrue($result['shipping']);
        $this->assertEquals('', $result['state']);
    }

    public function test_transforms_tax_rate_as_float(): void
    {
        $tax = (object) [
            'id' => 'ghi789',
            'name' => 'Zero Rate',
            'tax_rate' => '0',
        ];

        $result = $this->transformer->transform($tax);

        $this->assertEquals(0.0, $result['rate']);
        $this->assertIsFloat($result['rate']);
    }
}

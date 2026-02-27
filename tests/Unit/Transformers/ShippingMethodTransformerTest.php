<?php

namespace Tests\Unit\Transformers;

use App\Shopware\Transformers\ShippingMethodTransformer;
use PHPUnit\Framework\TestCase;

class ShippingMethodTransformerTest extends TestCase
{
    private ShippingMethodTransformer $transformer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transformer = new ShippingMethodTransformer;
    }

    public function test_transforms_basic_shipping_method(): void
    {
        $method = (object) [
            'id' => 'ship001',
            'name' => 'Standard Shipping',
            'description' => 'Standard delivery',
            'active' => true,
            'position' => 1,
            'tax_id' => 'tax001',
        ];

        $result = $this->transformer->transform($method);

        $this->assertEquals('shopware_ship001', $result['method_id']);
        $this->assertEquals('Standard Shipping', $result['method_title']);
        $this->assertTrue($result['enabled']);
        $this->assertEquals('taxable', $result['settings']['tax_status']);
    }

    public function test_transforms_with_technical_name(): void
    {
        $method = (object) [
            'id' => 'ship002',
            'name' => 'Express Shipping',
            'description' => 'Next day delivery',
            'technical_name' => 'shipping_express_delivery',
            'active' => true,
            'position' => 2,
            'tax_id' => null,
        ];

        $result = $this->transformer->transform($method);

        $this->assertEquals('shopware_ship002', $result['method_id']);
        $this->assertEquals('none', $result['settings']['tax_status']);

        // Verify technical_name is stored in meta_data
        $metaKeys = array_column($result['meta_data'], 'key');
        $this->assertContains('_shopware_technical_name', $metaKeys);

        $technicalNameMeta = array_filter(
            $result['meta_data'],
            fn ($m) => $m['key'] === '_shopware_technical_name'
        );
        $this->assertEquals('shipping_express_delivery', array_values($technicalNameMeta)[0]['value']);
    }

    public function test_transforms_without_technical_name(): void
    {
        $method = (object) [
            'id' => 'ship003',
            'name' => 'Free Shipping',
            'description' => '',
            'technical_name' => null,
            'active' => false,
            'position' => 3,
            'tax_id' => null,
        ];

        $result = $this->transformer->transform($method);

        $this->assertFalse($result['enabled']);

        // technical_name should NOT be in meta_data when null
        $metaKeys = array_column($result['meta_data'], 'key');
        $this->assertNotContains('_shopware_technical_name', $metaKeys);

        // meta_data must be a sequential array so it JSON-encodes as an array
        $this->assertTrue(array_is_list($result['meta_data']));
    }

    public function test_transforms_with_prices(): void
    {
        $method = (object) [
            'id' => 'ship004',
            'name' => 'Flat Rate',
            'description' => '',
            'active' => true,
            'position' => 1,
            'tax_id' => 'tax001',
        ];

        $prices = [
            (object) [
                'id' => 'price001',
                'currency_price' => json_encode(['default' => ['gross' => 4.99, 'net' => 4.20]]),
                'quantity_start' => 1,
                'quantity_end' => null,
                'calculation' => 1,
            ],
        ];

        $result = $this->transformer->transform($method, $prices);

        $this->assertEquals('4.99', $result['settings']['cost']);
    }
}

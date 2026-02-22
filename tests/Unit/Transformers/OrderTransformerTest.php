<?php

namespace Tests\Unit\Transformers;

use App\Shopware\Transformers\OrderTransformer;
use PHPUnit\Framework\TestCase;

class OrderTransformerTest extends TestCase
{
    private OrderTransformer $transformer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transformer = new OrderTransformer;
    }

    public function test_maps_order_status(): void
    {
        $this->assertEquals('pending', OrderTransformer::STATUS_MAP['open']);
        $this->assertEquals('processing', OrderTransformer::STATUS_MAP['in_progress']);
        $this->assertEquals('completed', OrderTransformer::STATUS_MAP['completed']);
        $this->assertEquals('cancelled', OrderTransformer::STATUS_MAP['cancelled']);
        $this->assertEquals('refunded', OrderTransformer::STATUS_MAP['returned']);
        $this->assertEquals('failed', OrderTransformer::STATUS_MAP['failed']);
        $this->assertEquals('on-hold', OrderTransformer::STATUS_MAP['reminded']);
    }

    public function test_transforms_basic_order(): void
    {
        $order = (object) [
            'order_number' => 'SW-10001',
            'order_date' => '2025-01-15 14:30:00',
            'status' => 'completed',
            'customer_comment' => 'Please deliver before noon',
        ];

        $customer = (object) [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@example.com',
        ];

        $billing = (object) [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'street' => '123 Main St',
            'zipcode' => '12345',
            'city' => 'Berlin',
            'company' => '',
            'address_2' => '',
            'phone' => '+49123456',
            'country_iso' => 'DE',
            'state_code' => '',
        ];

        $result = $this->transformer->transform($order, $customer, $billing);

        $this->assertEquals('completed', $result['status']);
        $this->assertTrue($result['set_paid']);
        $this->assertEquals('Please deliver before noon', $result['customer_note']);
        $this->assertEquals('john@example.com', $result['billing']['email']);
        $this->assertEquals('123 Main St', $result['billing']['address_1']);
        $this->assertEquals('DE', $result['billing']['country']);

        $meta = collect($result['meta_data']);
        $this->assertEquals('SW-10001', $meta->firstWhere('key', '_shopware_order_number')['value']);
    }

    public function test_transforms_line_items(): void
    {
        $order = (object) [
            'order_number' => 'SW-10002',
            'order_date' => '2025-01-15 14:30:00',
            'status' => 'open',
            'customer_comment' => '',
        ];

        $lineItems = [
            (object) [
                'type' => 'product',
                'name' => 'Widget',
                'quantity' => 2,
                'unit_price' => 15.00,
                'total_price' => 30.00,
                'payload' => json_encode(['productNumber' => 'WDG-001']),
            ],
            (object) [
                'type' => 'promotion',
                'name' => '10% Off',
                'quantity' => 1,
                'unit_price' => -3.00,
                'total_price' => -3.00,
                'payload' => '{}',
            ],
        ];

        $result = $this->transformer->transform($order, lineItems: $lineItems);

        $this->assertCount(1, $result['line_items']);
        $this->assertEquals('Widget', $result['line_items'][0]['name']);
        $this->assertEquals(2, $result['line_items'][0]['quantity']);
        $this->assertEquals('WDG-001', $result['line_items'][0]['sku']);
    }
}

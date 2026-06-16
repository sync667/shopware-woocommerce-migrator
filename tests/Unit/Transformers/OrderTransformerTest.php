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
        $this->assertEquals('Please deliver before noon', $result['customer_note']);
        $this->assertEquals('john@example.com', $result['billing']['email']);
        $this->assertEquals('123 Main St', $result['billing']['address_1']);
        $this->assertEquals('DE', $result['billing']['country']);

        $meta = collect($result['meta_data']);
        $this->assertEquals('SW-10001', $meta->firstWhere('key', '_shopware_order_number')['value']);
    }

    public function test_emits_gmt_date_fields_so_wc_does_not_apply_site_timezone(): void
    {
        // Bug fix: previously sent `date_created` (no TZ), which WC interpreted as
        // site-local time and shifted every order by the site's UTC offset. The _gmt
        // variant is explicit UTC.
        $order = (object) [
            'order_number' => '12345',
            'order_date' => '2024-06-25 12:33:47.893',
            'updated_at' => '2024-07-24 08:31:04.123',
            'status' => 'completed',
        ];

        $result = $this->transformer->transform($order, null, null);

        $this->assertArrayNotHasKey('date_created', $result, 'must NOT use non-GMT field — that one is site-timezone-relative');
        $this->assertSame('2024-06-25T12:33:47', $result['date_created_gmt']);
        $this->assertSame('2024-07-24T08:31:04', $result['date_modified_gmt']);
        $this->assertSame('2024-06-25T12:33:47', $result['date_paid_gmt'], 'completed orders are paid at checkout time');
        $this->assertSame('2024-07-24T08:31:04', $result['date_completed_gmt'], 'completed_gmt uses updated_at (closer estimate than order_date)');
    }

    public function test_cancelled_order_has_no_paid_or_completed_dates(): void
    {
        $order = (object) [
            'order_number' => '12345',
            'order_date' => '2024-06-25 12:33:47',
            'updated_at' => '2024-06-26 10:00:00',
            'status' => 'cancelled',
        ];

        $result = $this->transformer->transform($order, null, null);

        $this->assertArrayNotHasKey('date_paid_gmt', $result);
        $this->assertArrayNotHasKey('date_completed_gmt', $result);
        $this->assertArrayHasKey('date_created_gmt', $result);
        $this->assertArrayHasKey('date_modified_gmt', $result);
    }

    public function test_in_progress_order_marks_paid_but_not_completed(): void
    {
        $order = (object) [
            'order_number' => '12345',
            'order_date' => '2024-06-25 12:33:47',
            'updated_at' => '2024-06-25 13:00:00',
            'status' => 'in_progress',
        ];

        $result = $this->transformer->transform($order, null, null);

        $this->assertArrayHasKey('date_paid_gmt', $result, 'in_progress is paid');
        $this->assertArrayNotHasKey('date_completed_gmt', $result, 'in_progress is not yet completed');
    }

    public function test_normalize_datetime_handles_fractional_seconds_and_empty(): void
    {
        $this->assertSame('2024-01-15T10:30:45', OrderTransformer::normalizeDateTime('2024-01-15 10:30:45.999'));
        $this->assertSame('2024-01-15T10:30:45', OrderTransformer::normalizeDateTime('2024-01-15 10:30:45'));
        $this->assertNull(OrderTransformer::normalizeDateTime(null));
        $this->assertNull(OrderTransformer::normalizeDateTime(''));
        $this->assertNull(OrderTransformer::normalizeDateTime('0000-00-00 00:00:00'));
    }

    public function test_emits_order_number_meta_for_display_filter(): void
    {
        $order = (object) [
            'order_number' => '21038',
            'order_date' => '2025-04-01 09:00:00',
            'status' => 'completed',
        ];

        $result = $this->transformer->transform($order, null, null);

        $meta = collect($result['meta_data']);
        $display = $meta->firstWhere('key', '_order_number');
        $idempotency = $meta->firstWhere('key', '_shopware_order_number');

        $this->assertNotNull($display);
        $this->assertSame('21038', $display['value']);
        $this->assertSame('string', gettype($display['value']));

        $this->assertNotNull($idempotency);
        $this->assertSame('21038', $idempotency['value']);
    }

    public function test_order_number_meta_handles_missing_source_gracefully(): void
    {
        $order = (object) [
            'order_date' => '2025-04-01 09:00:00',
            'status' => 'pending',
        ];

        $result = $this->transformer->transform($order, null, null);

        $display = collect($result['meta_data'])->firstWhere('key', '_order_number');
        $this->assertNotNull($display);
        $this->assertSame('', $display['value']);
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

    public function test_emits_single_tracking_meta_with_all_codes(): void
    {
        // Regression: previously the loop emitted one meta per tracking code.
        // WP/Woo deduplicates post-meta with the same key, so only the last
        // code survived. The transformer must emit one meta with all items.
        $order = (object) [
            'order_number' => 'SW-10003',
            'order_date' => '2025-01-15 14:30:00',
            'status' => 'completed',
            'customer_comment' => '',
        ];

        $result = $this->transformer->transform(
            $order,
            trackingCodes: ['TRK-A', 'TRK-B', 'TRK-C']
        );

        $trackingMetas = array_values(array_filter(
            $result['meta_data'],
            fn ($m) => $m['key'] === '_wc_shipment_tracking_items'
        ));

        $this->assertCount(1, $trackingMetas, 'Should emit exactly one tracking meta entry');
        $this->assertCount(3, $trackingMetas[0]['value'], 'All tracking codes must be inside one value array');
        $this->assertSame('TRK-A', $trackingMetas[0]['value'][0]['tracking_number']);
        $this->assertSame('TRK-B', $trackingMetas[0]['value'][1]['tracking_number']);
        $this->assertSame('TRK-C', $trackingMetas[0]['value'][2]['tracking_number']);
    }

    public function test_no_tracking_meta_when_no_codes(): void
    {
        $order = (object) [
            'order_number' => 'SW-10004',
            'order_date' => '2025-01-15 14:30:00',
            'status' => 'completed',
            'customer_comment' => '',
        ];

        $result = $this->transformer->transform($order);

        $hasTracking = collect($result['meta_data'])->contains(fn ($m) => $m['key'] === '_wc_shipment_tracking_items');
        $this->assertFalse($hasTracking);
    }
}

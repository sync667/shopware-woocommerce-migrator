<?php

namespace Tests\Unit\Transformers;

use App\Shopware\Transformers\PaymentMethodTransformer;
use PHPUnit\Framework\TestCase;

class PaymentMethodTransformerTest extends TestCase
{
    private PaymentMethodTransformer $transformer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transformer = new PaymentMethodTransformer;
    }

    public function test_transforms_basic_payment_method(): void
    {
        $method = (object) [
            'id' => 'abc123',
            'name' => 'Invoice',
            'description' => 'Pay by invoice',
            'handler_identifier' => 'Shopware\\Core\\Checkout\\Payment\\Cart\\PaymentHandler\\InvoicePayment',
            'active' => true,
            'position' => 1,
            'after_order_enabled' => false,
        ];

        $result = $this->transformer->transform($method);

        $this->assertEquals('shopware_abc123', $result['id']);
        $this->assertEquals('Invoice', $result['title']);
        $this->assertTrue($result['enabled']);
        $this->assertEquals('Invoice payment method', $result['method_description']);
    }

    public function test_transforms_with_technical_name(): void
    {
        $method = (object) [
            'id' => 'def456',
            'name' => 'PayPal',
            'description' => 'Pay with PayPal',
            'handler_identifier' => 'Some\\Custom\\Handler',
            'technical_name' => 'payment_paypal_checkout',
            'active' => true,
            'position' => 2,
            'after_order_enabled' => true,
        ];

        $result = $this->transformer->transform($method);

        $this->assertEquals('shopware_def456', $result['id']);
        $this->assertEquals('PayPal', $result['title']);
        $this->assertEquals('PayPal payment gateway', $result['method_description']);

        // Verify technical_name is stored in meta_data
        $metaKeys = array_column($result['meta_data'], 'key');
        $this->assertContains('_shopware_technical_name', $metaKeys);

        $technicalNameMeta = array_filter(
            $result['meta_data'],
            fn ($m) => $m['key'] === '_shopware_technical_name'
        );
        $this->assertEquals('payment_paypal_checkout', array_values($technicalNameMeta)[0]['value']);
    }

    public function test_transforms_without_technical_name(): void
    {
        $method = (object) [
            'id' => 'ghi789',
            'name' => 'Prepayment',
            'description' => '',
            'handler_identifier' => 'Shopware\\Core\\Checkout\\Payment\\Cart\\PaymentHandler\\PrepaymentPayment',
            'technical_name' => null,
            'active' => true,
            'position' => 3,
            'after_order_enabled' => false,
        ];

        $result = $this->transformer->transform($method);

        // Should fall back to handler_identifier
        $this->assertEquals('Prepayment / Bank transfer', $result['method_description']);

        // technical_name should NOT be in meta_data when null
        $metaKeys = array_column($result['meta_data'], 'key');
        $this->assertNotContains('_shopware_technical_name', $metaKeys);

        // meta_data must remain a sequential numeric array so it JSON-encodes as a list
        $this->assertTrue(array_is_list($result['meta_data']));
    }

    public function test_handler_identifier_fallback_when_technical_name_not_matching(): void
    {
        $method = (object) [
            'id' => 'jkl012',
            'name' => 'Cash on Delivery',
            'description' => '',
            'handler_identifier' => 'Shopware\\Core\\Checkout\\Payment\\Cart\\PaymentHandler\\CashOnDeliveryPayment',
            'technical_name' => 'temporary_abc123',
            'active' => true,
            'position' => 4,
            'after_order_enabled' => false,
        ];

        $result = $this->transformer->transform($method);

        // technical_name with 'temporary_' prefix doesn't match any known pattern,
        // so falls through to handler_identifier check
        $this->assertEquals('Cash on delivery', $result['method_description']);
    }
}

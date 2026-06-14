<?php

namespace Tests\Unit\Transformers;

use App\Shopware\Transformers\DeliveryTierTransformer;
use InvalidArgumentException;
use Tests\TestCase;

class DeliveryTierTransformerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['migration.companion.shopware_tier_field' => 'shipping_tiers']);
    }

    public function test_returns_null_when_custom_fields_absent(): void
    {
        $this->assertNull(DeliveryTierTransformer::extract((object) []));
        $this->assertNull(DeliveryTierTransformer::extract((object) ['custom_fields' => null]));
        $this->assertNull(DeliveryTierTransformer::extract((object) ['custom_fields' => '']));
        $this->assertNull(DeliveryTierTransformer::extract((object) ['custom_fields' => []]));
    }

    public function test_returns_null_when_tier_key_missing(): void
    {
        $product = (object) ['custom_fields' => json_encode(['product_price_change_date' => '2024-01-01'])];

        $this->assertNull(DeliveryTierTransformer::extract($product));
    }

    public function test_decodes_two_tier_payload(): void
    {
        $product = (object) [
            'custom_fields' => '{"shipping_tiers":[{"quantityFrom":1,"quantityTo":1,"grossPrice":200},{"quantityFrom":2,"quantityTo":2,"grossPrice":300}]}',
        ];

        $tiers = DeliveryTierTransformer::extract($product);

        $this->assertSame([
            ['from' => 1, 'to' => 1, 'cost' => 200.00],
            ['from' => 2, 'to' => 2, 'cost' => 300.00],
        ], $tiers);
    }

    public function test_open_ended_top_tier_keeps_null_to(): void
    {
        $product = (object) [
            'custom_fields' => '{"shipping_tiers":[{"quantityFrom":1,"quantityTo":5,"grossPrice":100},{"quantityFrom":6,"quantityTo":null,"grossPrice":500}]}',
        ];

        $tiers = DeliveryTierTransformer::extract($product);

        $this->assertSame(1, $tiers[0]['from']);
        $this->assertSame(5, $tiers[0]['to']);
        $this->assertSame(6, $tiers[1]['from']);
        $this->assertNull($tiers[1]['to']);
    }

    public function test_tiers_are_sorted_by_from_ascending(): void
    {
        $product = (object) [
            'custom_fields' => '{"shipping_tiers":[{"quantityFrom":21,"quantityTo":null,"grossPrice":500},{"quantityFrom":1,"quantityTo":5,"grossPrice":100},{"quantityFrom":6,"quantityTo":20,"grossPrice":300}]}',
        ];

        $tiers = DeliveryTierTransformer::extract($product);

        $this->assertSame([1, 6, 21], array_column($tiers, 'from'));
    }

    public function test_throws_when_from_is_below_one(): void
    {
        $product = (object) [
            'custom_fields' => '{"shipping_tiers":[{"quantityFrom":0,"quantityTo":5,"grossPrice":100}]}',
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("'from' must be >= 1");

        DeliveryTierTransformer::extract($product);
    }

    public function test_throws_when_to_less_than_from(): void
    {
        $product = (object) [
            'custom_fields' => '{"shipping_tiers":[{"quantityFrom":5,"quantityTo":2,"grossPrice":100}]}',
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("'to' (2) must be >= 'from' (5)");

        DeliveryTierTransformer::extract($product);
    }

    public function test_throws_when_cost_is_negative(): void
    {
        $product = (object) [
            'custom_fields' => '{"shipping_tiers":[{"quantityFrom":1,"quantityTo":null,"grossPrice":-10}]}',
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("'cost' must be >= 0");

        DeliveryTierTransformer::extract($product);
    }

    public function test_throws_when_required_keys_missing(): void
    {
        $product = (object) [
            'custom_fields' => '{"shipping_tiers":[{"quantityTo":5,"grossPrice":100}]}',
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("missing or non-numeric 'quantityFrom'");

        DeliveryTierTransformer::extract($product);
    }

    public function test_cost_is_rounded_to_two_decimals(): void
    {
        $product = (object) [
            'custom_fields' => '{"shipping_tiers":[{"quantityFrom":1,"quantityTo":null,"grossPrice":99.999}]}',
        ];

        $tiers = DeliveryTierTransformer::extract($product);

        $this->assertSame(100.0, $tiers[0]['cost']);
    }

    public function test_accepts_object_payload_not_just_string(): void
    {
        $product = (object) [
            'custom_fields' => [
                'shipping_tiers' => [
                    ['quantityFrom' => 1, 'quantityTo' => null, 'grossPrice' => 250],
                ],
            ],
        ];

        $tiers = DeliveryTierTransformer::extract($product);

        $this->assertSame([['from' => 1, 'to' => null, 'cost' => 250.00]], $tiers);
    }

    public function test_field_name_is_configurable_via_config(): void
    {
        config(['migration.companion.shopware_tier_field' => 'my_custom_tiers']);

        $product = (object) [
            'custom_fields' => '{"my_custom_tiers":[{"quantityFrom":1,"quantityTo":null,"grossPrice":50}]}',
        ];

        $tiers = DeliveryTierTransformer::extract($product);

        $this->assertSame([['from' => 1, 'to' => null, 'cost' => 50.00]], $tiers);
    }
}

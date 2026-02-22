<?php

namespace Tests\Unit\Transformers;

use App\Shopware\Transformers\CouponTransformer;
use PHPUnit\Framework\TestCase;

class CouponTransformerTest extends TestCase
{
    private CouponTransformer $transformer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transformer = new CouponTransformer;
    }

    public function test_transforms_percentage_coupon(): void
    {
        $promotion = (object) [
            'code' => 'SUMMER10',
            'name' => 'Summer Sale 10%',
            'valid_from' => '2025-06-01',
            'valid_until' => '2025-08-31',
            'usage_limit' => 100,
            'usage_limit_per_user' => 1,
        ];

        $discounts = [
            (object) [
                'discount_type' => 'percentage',
                'discount_value' => 10.0,
            ],
        ];

        $result = $this->transformer->transform($promotion, $discounts);

        $this->assertEquals('SUMMER10', $result['code']);
        $this->assertEquals('Summer Sale 10%', $result['description']);
        $this->assertEquals('percent', $result['discount_type']);
        $this->assertEquals('10', $result['amount']);
        $this->assertEquals('2025-06-01', $result['date_created']);
        $this->assertEquals('2025-08-31', $result['date_expires']);
        $this->assertEquals(100, $result['usage_limit']);
        $this->assertEquals(1, $result['usage_limit_per_user']);
    }

    public function test_transforms_fixed_cart_coupon(): void
    {
        $promotion = (object) [
            'code' => 'FLAT5',
            'name' => '5 PLN off',
        ];

        $discounts = [
            (object) [
                'discount_type' => 'absolute',
                'discount_value' => 5.00,
            ],
        ];

        $result = $this->transformer->transform($promotion, $discounts);

        $this->assertEquals('FLAT5', $result['code']);
        $this->assertEquals('fixed_cart', $result['discount_type']);
        $this->assertEquals('5', $result['amount']);
    }

    public function test_uses_individual_code_when_provided(): void
    {
        $promotion = (object) [
            'code' => 'PROMO-MAIN',
            'name' => 'Individual promo',
        ];

        $result = $this->transformer->transform($promotion, [], 'INDIVIDUAL-CODE-123');

        $this->assertEquals('INDIVIDUAL-CODE-123', $result['code']);
    }

    public function test_handles_empty_discounts(): void
    {
        $promotion = (object) [
            'code' => 'EMPTY',
            'name' => 'No discount',
        ];

        $result = $this->transformer->transform($promotion, []);

        $this->assertEquals('fixed_cart', $result['discount_type']);
        $this->assertEquals('0', $result['amount']);
    }

    public function test_omits_optional_fields_when_not_set(): void
    {
        $promotion = (object) [
            'code' => 'SIMPLE',
            'name' => 'Simple',
        ];

        $result = $this->transformer->transform($promotion);

        $this->assertArrayNotHasKey('date_created', $result);
        $this->assertArrayNotHasKey('date_expires', $result);
        $this->assertArrayNotHasKey('usage_limit', $result);
        $this->assertArrayNotHasKey('usage_limit_per_user', $result);
    }
}

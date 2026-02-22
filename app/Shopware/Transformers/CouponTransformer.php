<?php

namespace App\Shopware\Transformers;

class CouponTransformer
{
    public function transform(object $promotion, array $discounts = [], ?string $code = null): array
    {
        $discount = $discounts[0] ?? null;

        $data = [
            'code' => $code ?: ($promotion->code ?? ''),
            'description' => $promotion->name ?? '',
            'discount_type' => $this->mapDiscountType($discount),
            'amount' => $discount ? (string) round((float) $discount->discount_value, 2) : '0',
        ];

        if (! empty($promotion->valid_from)) {
            $data['date_created'] = $promotion->valid_from;
        }

        if (! empty($promotion->valid_until)) {
            $data['date_expires'] = $promotion->valid_until;
        }

        if (isset($promotion->usage_limit)) {
            $data['usage_limit'] = (int) $promotion->usage_limit;
        }

        if (isset($promotion->usage_limit_per_user)) {
            $data['usage_limit_per_user'] = (int) $promotion->usage_limit_per_user;
        }

        return $data;
    }

    protected function mapDiscountType(?object $discount): string
    {
        if (! $discount) {
            return 'fixed_cart';
        }

        return match ($discount->discount_type ?? '') {
            'percentage' => 'percent',
            default => 'fixed_cart',
        };
    }
}

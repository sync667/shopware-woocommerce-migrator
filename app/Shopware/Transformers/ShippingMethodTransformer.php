<?php

namespace App\Shopware\Transformers;

class ShippingMethodTransformer
{
    public function transform(object $shippingMethod, array $prices = []): array
    {
        $defaultPrice = $this->getDefaultPrice($prices);

        return [
            'method_id' => 'shopware_'.($shippingMethod->id ?? ''),
            'method_title' => $shippingMethod->name ?: 'Unnamed Shipping Method',
            'method_description' => $shippingMethod->description ?? '',
            'enabled' => (bool) ($shippingMethod->active ?? false),
            'settings' => [
                'cost' => $defaultPrice,
                'tax_status' => $shippingMethod->tax_id ? 'taxable' : 'none',
            ],
            'meta_data' => [
                ['key' => '_shopware_id', 'value' => $shippingMethod->id ?? ''],
                ['key' => '_shopware_position', 'value' => $shippingMethod->position ?? 0],
                ['key' => '_shopware_tax_id', 'value' => $shippingMethod->tax_id ?? ''],
                ['key' => '_shopware_prices', 'value' => json_encode($prices)],
            ],
        ];
    }

    protected function getDefaultPrice(array $prices): string
    {
        if (empty($prices)) {
            return '0';
        }

        // Get the first price (usually the base price)
        $firstPrice = $prices[0] ?? null;
        if (! $firstPrice) {
            return '0';
        }

        // Parse currency_price JSON
        $currencyPrices = json_decode($firstPrice->currency_price ?? '{}', true);
        if (empty($currencyPrices) || ! is_array($currencyPrices)) {
            return '0';
        }

        // Get the first currency price
        $price = reset($currencyPrices);

        return (string) round((float) ($price['gross'] ?? 0), 2);
    }
}

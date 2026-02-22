<?php

namespace App\Shopware\Transformers;

class ProductTransformer
{
    public function transform(
        object $product,
        array $categoryWooIds = [],
        ?int $manufacturerWooId = null,
        string $taxClassSlug = '',
        array $attributes = [],
        array $tags = [],
    ): array {
        $prices = $this->parsePrices($product->price ?? '[]');

        $data = [
            'name' => $product->name ?: 'Unnamed Product',
            'sku' => $product->sku ?? '',
            'type' => $this->mapProductType($product->type ?? 'product'),
            'status' => ($product->active ?? false) ? 'publish' : 'draft',
            'description' => $product->description ?? '',
            'short_description' => '',
            'regular_price' => $prices['regular'],
            'manage_stock' => (bool) ($product->manage_stock ?? false),
            'stock_quantity' => (int) ($product->stock ?? 0),
            'weight' => $this->gramsToKg($product->weight ?? 0),
            'dimensions' => [
                'length' => $this->mmToCm($product->depth ?? 0),
                'width' => $this->mmToCm($product->width ?? 0),
                'height' => $this->mmToCm($product->height ?? 0),
            ],
            'tax_class' => $taxClassSlug,
            'categories' => array_map(fn ($id) => ['id' => $id], $categoryWooIds),
        ];

        if ($prices['sale'] !== null) {
            $data['sale_price'] = $prices['sale'];
        }

        if (! empty($tags)) {
            $data['tags'] = array_map(fn ($name) => ['name' => $name], $tags);
        }

        if (! empty($attributes)) {
            $data['attributes'] = $attributes;
        }

        $data['meta_data'] = [];

        if ($product->meta_title ?? '') {
            $data['meta_data'][] = ['key' => '_yoast_wpseo_title', 'value' => $product->meta_title];
        }

        if ($product->meta_description ?? '') {
            $data['meta_data'][] = ['key' => '_yoast_wpseo_metadesc', 'value' => $product->meta_description];
        }

        if ($manufacturerWooId) {
            $data['meta_data'][] = ['key' => '_manufacturer_id', 'value' => (string) $manufacturerWooId];
        }

        return $data;
    }

    public function transformVariant(object $variant, array $optionAttributes = []): array
    {
        $prices = $this->parsePrices($variant->price ?? '[]');

        $data = [
            'sku' => $variant->sku ?? '',
            'regular_price' => $prices['regular'],
            'manage_stock' => (bool) ($variant->manage_stock ?? false),
            'stock_quantity' => (int) ($variant->stock ?? 0),
            'weight' => $this->gramsToKg($variant->weight ?? 0),
        ];

        if ($prices['sale'] !== null) {
            $data['sale_price'] = $prices['sale'];
        }

        if (! empty($optionAttributes)) {
            $data['attributes'] = $optionAttributes;
        }

        return $data;
    }

    public function buildAttributes(array $configuratorSettings, bool $isVariation = true): array
    {
        $grouped = [];
        foreach ($configuratorSettings as $setting) {
            $groupName = $setting->group_name ?? 'Unknown';
            $optionName = $setting->option_name ?? '';
            if (! isset($grouped[$groupName])) {
                $grouped[$groupName] = [];
            }
            $grouped[$groupName][] = $optionName;
        }

        $attributes = [];
        $position = 0;
        foreach ($grouped as $name => $options) {
            $attributes[] = [
                'name' => $name,
                'options' => array_unique($options),
                'visible' => true,
                'variation' => $isVariation,
                'position' => $position++,
            ];
        }

        return $attributes;
    }

    public function buildVariantOptionAttributes(array $variantOptions): array
    {
        $attributes = [];
        foreach ($variantOptions as $option) {
            $attributes[] = [
                'name' => $option->group_name ?? 'Unknown',
                'option' => $option->option_name ?? '',
            ];
        }

        return $attributes;
    }

    protected function parsePrices(string $priceJson): array
    {
        $prices = json_decode($priceJson, true);

        if (empty($prices) || ! is_array($prices)) {
            return ['regular' => '0', 'sale' => null];
        }

        $price = $prices[0] ?? [];
        $gross = (float) ($price['gross'] ?? 0);
        $listPrice = isset($price['listPrice']['gross']) ? $price['listPrice']['gross'] : null;

        if ($listPrice !== null && (float) $listPrice > $gross) {
            return [
                'regular' => (string) round((float) $listPrice, 2),
                'sale' => (string) round($gross, 2),
            ];
        }

        return [
            'regular' => (string) round($gross, 2),
            'sale' => null,
        ];
    }

    protected function gramsToKg(float $grams): string
    {
        if ($grams <= 0) {
            return '';
        }

        return (string) round($grams / 1000, 3);
    }

    protected function mmToCm(float $mm): string
    {
        if ($mm <= 0) {
            return '';
        }

        return (string) round($mm / 10, 2);
    }

    protected function mapProductType(?string $shopwareType): string
    {
        return match ($shopwareType) {
            'grouped' => 'grouped',
            default => 'simple',
        };
    }
}

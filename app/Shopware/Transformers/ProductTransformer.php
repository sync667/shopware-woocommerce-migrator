<?php

namespace App\Shopware\Transformers;

use App\Services\ContentMigrator;

class ProductTransformer
{
    public function __construct(
        protected ?ContentMigrator $contentMigrator = null
    ) {}

    public function transform(
        object $product,
        array $categoryWooIds = [],
        ?int $manufacturerWooId = null,
        string $taxClassSlug = '',
        array $attributes = [],
        array $tags = [],
    ): array {
        $prices = $this->parsePrices($product->price ?? '[]');

        // Process description with ContentMigrator if available
        $description = $product->description ?? '';
        if ($this->contentMigrator && ! empty($description)) {
            $description = $this->contentMigrator->processHtmlContent($description);
        }

        // Generate short description from full description
        $shortDescription = '';
        if ($this->contentMigrator && ! empty($description)) {
            $shortDescription = $this->contentMigrator->extractPlainText($description, 150);
        }

        $manageStock = (bool) ($product->manage_stock ?? false);
        $stockQuantity = (int) ($product->stock ?? 0);
        $available = $product->available ?? true;

        $data = [
            'name' => $product->name ?: 'Unnamed Product',
            'sku' => $product->sku ?? '',
            'type' => $this->mapProductType($product->type ?? 'product'),
            'status' => ($product->active ?? false) ? 'publish' : 'draft',
            'description' => $description,
            'short_description' => $shortDescription,
            'regular_price' => $prices['regular'],
            'manage_stock' => $manageStock,
            'stock_quantity' => $stockQuantity,
            'stock_status' => ($manageStock || $available) ? 'instock' : 'outofstock',
            'weight' => $this->gramsToKg($product->weight ?? 0),
            'dimensions' => [
                'length' => $this->mmToCm($product->depth ?? 0),
                'width' => $this->mmToCm($product->width ?? 0),
                'height' => $this->mmToCm($product->height ?? 0),
            ],
            'tax_class' => $taxClassSlug,
            'categories' => array_map(fn ($id) => ['id' => $id], $categoryWooIds),
        ];

        // Map Shopware visibility to WooCommerce catalog_visibility
        $maxVisibility = (int) ($product->max_visibility ?? 0);
        if ($maxVisibility >= 30) {
            $data['catalog_visibility'] = 'visible';
        } elseif ($maxVisibility >= 10) {
            $data['catalog_visibility'] = 'search';
        } elseif ($maxVisibility === 0 && isset($product->max_visibility)) {
            $data['catalog_visibility'] = 'hidden';
        }

        // Product creation date
        if (! empty($product->created_at)) {
            try {
                $data['date_created'] = (new \DateTime($product->created_at))->format('Y-m-d\TH:i:s');
            } catch (\Exception) {
            }
        }

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

        // Store Shopware product ID and number for reference
        if ($product->id ?? '') {
            $data['meta_data'][] = ['key' => '_shopware_product_id', 'value' => $product->id];
        }

        if ($product->sku ?? '') {
            $data['meta_data'][] = ['key' => '_shopware_product_number', 'value' => $product->sku];
        }

        if ($product->meta_title ?? '') {
            $data['meta_data'][] = ['key' => '_yoast_wpseo_title', 'value' => $product->meta_title];
        }

        if ($product->meta_description ?? '') {
            $data['meta_data'][] = ['key' => '_yoast_wpseo_metadesc', 'value' => $product->meta_description];
        }

        if ($manufacturerWooId) {
            $data['meta_data'][] = ['key' => '_manufacturer_id', 'value' => (string) $manufacturerWooId];
        }

        if ($product->ean ?? '') {
            $data['meta_data'][] = ['key' => '_ean', 'value' => $product->ean];
            $data['meta_data'][] = ['key' => '_global_unique_id', 'value' => $product->ean]; // WC 9.2+ GTIN
        }

        if ($product->manufacturer_number ?? '') {
            $data['meta_data'][] = ['key' => '_manufacturer_number', 'value' => $product->manufacturer_number];
        }

        if (isset($product->min_purchase) && $product->min_purchase > 1) {
            $data['meta_data'][] = ['key' => '_min_purchase', 'value' => (int) $product->min_purchase];
        }

        if (isset($product->max_purchase) && $product->max_purchase > 0) {
            $data['meta_data'][] = ['key' => '_max_purchase', 'value' => (int) $product->max_purchase];
        }

        if (isset($product->purchase_steps) && $product->purchase_steps > 1) {
            $data['meta_data'][] = ['key' => '_purchase_steps', 'value' => (int) $product->purchase_steps];
        }

        if ($product->purchase_unit ?? null) {
            $data['meta_data'][] = ['key' => '_purchase_unit', 'value' => (float) $product->purchase_unit];
        }

        if ($product->reference_unit ?? null) {
            $data['meta_data'][] = ['key' => '_reference_unit', 'value' => (float) $product->reference_unit];
        }

        if (isset($product->shipping_free) && $product->shipping_free) {
            $data['meta_data'][] = ['key' => '_shipping_free', 'value' => true];
        }

        if (isset($product->mark_as_topseller) && $product->mark_as_topseller) {
            $data['meta_data'][] = ['key' => '_featured', 'value' => true];
            $data['featured'] = true;
        }

        if (isset($product->available)) {
            $data['meta_data'][] = ['key' => '_available', 'value' => (bool) $product->available];
        }

        // Custom search keywords
        if (! empty($product->keywords)) {
            $keywords = json_decode($product->keywords, true);
            if (is_array($keywords) && ! empty($keywords)) {
                $data['meta_data'][] = ['key' => '_custom_search_keywords', 'value' => implode(', ', $keywords)];
            }
        }

        // Custom fields (stored as individual _sw_cf_* meta entries)
        if (! empty($product->custom_fields)) {
            $customFields = is_string($product->custom_fields)
                ? json_decode($product->custom_fields, true)
                : (array) $product->custom_fields;
            if (is_array($customFields)) {
                foreach ($customFields as $key => $value) {
                    if ($value !== null && $value !== '' && $value !== []) {
                        $data['meta_data'][] = ['key' => '_sw_cf_'.$key, 'value' => $value];
                    }
                }
            }
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

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
        ?int $primaryCategoryWooId = null,
        ?string $omnibusLowestPrice = null,
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

        // The reader aliases Shopware `is_closeout` as `manage_stock`, but the two flags
        // mean different things. Shopware ALWAYS tracks stock; `is_closeout` only controls
        // whether selling stops once stock hits zero. WC's `manage_stock` means "track
        // inventory at all", which in Shopware terms is always true.
        $isCloseout = (bool) ($product->manage_stock ?? false);
        $stockQuantity = (int) ($product->stock ?? 0);
        $available = (bool) ($product->available ?? true);

        $data = [
            'name' => $product->name ?: 'Unnamed Product',
            'sku' => $product->sku ?? '',
            'type' => $this->mapProductType($product->type ?? 'product'),
            'status' => ($product->active ?? false) ? 'publish' : 'draft',
            'description' => $description,
            'short_description' => $shortDescription,
            'regular_price' => $prices['regular'],
            'manage_stock' => true,
            'stock_quantity' => $stockQuantity,
            'stock_status' => $this->stockStatus($isCloseout, $stockQuantity, $available),
            'weight' => $this->gramsToKg($product->weight ?? 0),
            'dimensions' => [
                'length' => $this->mmToCm($product->depth ?? 0),
                'width' => $this->mmToCm($product->width ?? 0),
                'height' => $this->mmToCm($product->height ?? 0),
            ],
            'tax_class' => $taxClassSlug,
            'categories' => array_map(fn ($id) => ['id' => $id], $categoryWooIds),
        ];

        // Shopware: 30=All, 20=Search, 10=Link-only, 0/NULL=no row. NULL means
        // the primary channel has no visibility row → must emit `hidden` explicitly,
        // else WC defaults to visible.
        $rawVisibility = $product->max_visibility ?? null;
        if ($rawVisibility === null) {
            $data['catalog_visibility'] = 'hidden';
        } else {
            $maxVisibility = (int) $rawVisibility;
            if ($maxVisibility >= 30) {
                $data['catalog_visibility'] = 'visible';
            } elseif ($maxVisibility >= 20) {
                $data['catalog_visibility'] = 'search';
            } else {
                $data['catalog_visibility'] = 'hidden';
            }
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
            $data['featured'] = true;
        }

        if (isset($product->available)) {
            $data['meta_data'][] = ['key' => '_available', 'value' => (bool) $product->available];
        }

        // Cost of goods (Shopware `purchase_prices` JSON, same shape as `price`).
        // Maps to WooCommerce "Cost of Goods" plugin meta `_wc_cog_cost`.
        $cost = $this->extractGross($product->purchase_prices ?? null);
        if ($cost !== null && $cost > 0) {
            $data['meta_data'][] = ['key' => '_wc_cog_cost', 'value' => $this->formatMoney($cost)];
        }

        // release_date controls when the product becomes visible on the storefront.
        if (! empty($product->release_date)) {
            $data['meta_data'][] = ['key' => '_shopware_release_date', 'value' => (string) $product->release_date];
        }

        if ($primaryCategoryWooId !== null && $primaryCategoryWooId > 0) {
            // Yoast Primary Category for the product taxonomy.
            $data['meta_data'][] = ['key' => '_yoast_wpseo_primary_product_cat', 'value' => (string) $primaryCategoryWooId];
        }

        if ($omnibusLowestPrice !== null && $omnibusLowestPrice !== '') {
            // Lowest 30-day price (Polish Omnibus directive compliance). The target shop
            // needs a matching plugin (e.g. "WooCommerce PL Omnibus") to render it; the
            // meta is inert otherwise.
            $data['meta_data'][] = ['key' => '_omnibus_lowest_price', 'value' => $this->formatMoney((float) $omnibusLowestPrice)];
        }

        if (! empty($product->keywords)) {
            $keywords = json_decode($product->keywords, true);
            if (is_array($keywords) && ! empty($keywords)) {
                $joined = implode(', ', $keywords);
                $data['meta_data'][] = ['key' => '_custom_search_keywords', 'value' => $joined];

                // Focus keyphrase for Yoast / RankMath — they read these directly on
                // their own filters. First keyword is the operator's primary intent.
                $first = (string) $keywords[0];
                $data['meta_data'][] = ['key' => '_yoast_wpseo_focuskw', 'value' => $first];
                $data['meta_data'][] = ['key' => 'rank_math_focus_keyword', 'value' => $first];

                if (count($keywords) > 1) {
                    // Yoast Premium reads keyphrase synonyms from this; free version
                    // ignores it but the data survives a Premium upgrade.
                    $synonyms = array_slice($keywords, 1);
                    $data['meta_data'][] = ['key' => '_yoast_wpseo_keywordsynonyms', 'value' => json_encode($synonyms, JSON_UNESCAPED_UNICODE)];
                }
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

        // See parent transform() for the is_closeout vs. manage_stock semantics.
        $isCloseout = (bool) ($variant->manage_stock ?? false);
        $stock = (int) ($variant->stock ?? 0);
        $available = (bool) ($variant->available ?? true);

        $data = [
            'sku' => $variant->sku ?? '',
            'status' => ($variant->active ?? true) ? 'publish' : 'private',
            'regular_price' => $prices['regular'],
            'manage_stock' => true,
            'stock_quantity' => $stock,
            'stock_status' => $this->stockStatus($isCloseout, $stock, $available),
            'weight' => $this->gramsToKg($variant->weight ?? 0),
            'dimensions' => [
                'length' => $this->mmToCm($variant->depth ?? 0),
                'width' => $this->mmToCm($variant->width ?? 0),
                'height' => $this->mmToCm($variant->height ?? 0),
            ],
            // menu_order drives WC's variations list order in admin and is the
            // tiebreaker most storefront themes use when "default attribute"
            // selection doesn't narrow to a single variant.
            'menu_order' => (int) ($variant->display_order ?? 0),
        ];

        if ($prices['sale'] !== null) {
            $data['sale_price'] = $prices['sale'];
        }

        if (! empty($optionAttributes)) {
            $data['attributes'] = $optionAttributes;
        }

        $data['meta_data'] = [];

        if ($variant->ean ?? '') {
            $data['meta_data'][] = ['key' => '_ean', 'value' => $variant->ean];
        }

        if ($variant->manufacturer_number ?? '') {
            $data['meta_data'][] = ['key' => '_manufacturer_number', 'value' => $variant->manufacturer_number];
        }

        if (isset($variant->shipping_free) && $variant->shipping_free) {
            $data['meta_data'][] = ['key' => '_shipping_free', 'value' => true];
        }

        if (isset($variant->min_purchase) && $variant->min_purchase > 1) {
            $data['meta_data'][] = ['key' => '_min_purchase', 'value' => (int) $variant->min_purchase];
        }

        if (isset($variant->max_purchase) && $variant->max_purchase > 0) {
            $data['meta_data'][] = ['key' => '_max_purchase', 'value' => (int) $variant->max_purchase];
        }

        if (isset($variant->purchase_steps) && $variant->purchase_steps > 1) {
            $data['meta_data'][] = ['key' => '_purchase_steps', 'value' => (int) $variant->purchase_steps];
        }

        if (empty($data['meta_data'])) {
            unset($data['meta_data']);
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
            return ['regular' => '0.00', 'sale' => null];
        }

        $price = reset($prices) ?: [];
        $gross = (float) ($price['gross'] ?? 0);
        $listPrice = isset($price['listPrice']['gross']) ? (float) $price['listPrice']['gross'] : null;

        if ($listPrice !== null && $listPrice > $gross) {
            return [
                'regular' => $this->formatMoney($listPrice),
                'sale' => $this->formatMoney($gross),
            ];
        }

        return [
            'regular' => $this->formatMoney($gross),
            'sale' => null,
        ];
    }

    /**
     * Format a money value as a fixed 2-decimal string with no thousands separator.
     *
     * Uses number_format instead of round() to avoid PHP's banker's-rounding edge
     * cases (e.g. round(0.005, 2) varying by platform) and to guarantee the
     * trailing-zero form WooCommerce expects ('19.00' not '19').
     */
    protected function formatMoney(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    /**
     * Pull the `gross` value out of Shopware's price JSON shape
     * `{"<currencyId>": {"gross": ..., "net": ...}}`. Used for both
     * `purchase_prices` (cost of goods) and any other money JSON column.
     */
    protected function extractGross(mixed $raw): ?float
    {
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded) || $decoded === []) {
            return null;
        }
        $entry = reset($decoded);
        if (! is_array($entry) || ! isset($entry['gross'])) {
            return null;
        }

        return (float) $entry['gross'];
    }

    /**
     * WC `stock_status` derived from Shopware semantics:
     *   - `is_closeout=true`  : stop selling when stock hits zero
     *   - `is_closeout=false` : keep selling regardless of stock
     *   - `available=false`   : explicitly unavailable (e.g. discontinued in Shopware)
     */
    protected function stockStatus(bool $isCloseout, int $stock, bool $available): string
    {
        if (! $available) {
            return 'outofstock';
        }
        if ($isCloseout && $stock <= 0) {
            return 'outofstock';
        }

        return 'instock';
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

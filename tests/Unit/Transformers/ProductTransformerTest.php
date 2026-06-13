<?php

namespace Tests\Unit\Transformers;

use App\Shopware\Transformers\ProductTransformer;
use PHPUnit\Framework\TestCase;

class ProductTransformerTest extends TestCase
{
    private ProductTransformer $transformer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transformer = new ProductTransformer;
    }

    public function test_transforms_basic_product(): void
    {
        $product = (object) [
            'name' => 'Test Product',
            'sku' => 'SKU-001',
            'active' => true,
            'description' => 'A test product',
            'stock' => 10,
            'manage_stock' => true,
            'weight' => 500,
            'width' => 100,
            'height' => 50,
            'depth' => 200,
            'price' => json_encode([['gross' => 29.99, 'net' => 24.36, 'linked' => true]]),
            'type' => 'product',
            'meta_title' => 'Test SEO Title',
            'meta_description' => 'Test SEO Description',
        ];

        $result = $this->transformer->transform($product);

        $this->assertEquals('Test Product', $result['name']);
        $this->assertEquals('SKU-001', $result['sku']);
        $this->assertEquals('publish', $result['status']);
        $this->assertEquals('29.99', $result['regular_price']);
        $this->assertEquals(10, $result['stock_quantity']);
        $this->assertTrue($result['manage_stock']);
        $this->assertEquals('0.5', $result['weight']);
        $this->assertEquals('20', $result['dimensions']['length']);
        $this->assertEquals('10', $result['dimensions']['width']);
        $this->assertEquals('5', $result['dimensions']['height']);
        $this->assertEquals('simple', $result['type']);
    }

    private function visibilityProduct(?int $maxVisibility): object
    {
        return (object) [
            'name' => 'Probe',
            'sku' => 'SKU-VIS',
            'active' => true,
            'description' => '',
            'stock' => 0,
            'manage_stock' => false,
            'weight' => 0,
            'width' => 0,
            'height' => 0,
            'depth' => 0,
            'price' => '[]',
            'type' => 'product',
            'meta_title' => '',
            'meta_description' => '',
            'max_visibility' => $maxVisibility,
        ];
    }

    public function test_keywords_emit_yoast_and_rankmath_focus_meta(): void
    {
        $product = (object) [
            'name' => 'Probe',
            'sku' => 'SKU-KW',
            'active' => true,
            'description' => '',
            'stock' => 0,
            'manage_stock' => false,
            'weight' => 0, 'width' => 0, 'height' => 0, 'depth' => 0,
            'price' => '[]',
            'type' => 'product',
            'meta_title' => '', 'meta_description' => '',
            'keywords' => json_encode(['pompa zatapialna', 'pompa zanurzeniowa', 'evak'], JSON_UNESCAPED_UNICODE),
        ];

        $result = $this->transformer->transform($product);

        $meta = collect($result['meta_data']);
        $this->assertSame('pompa zatapialna, pompa zanurzeniowa, evak', $meta->firstWhere('key', '_custom_search_keywords')['value']);
        $this->assertSame('pompa zatapialna', $meta->firstWhere('key', '_yoast_wpseo_focuskw')['value']);
        $this->assertSame('pompa zatapialna', $meta->firstWhere('key', 'rank_math_focus_keyword')['value']);

        $synonyms = json_decode($meta->firstWhere('key', '_yoast_wpseo_keywordsynonyms')['value'], true);
        $this->assertSame(['pompa zanurzeniowa', 'evak'], $synonyms);
    }

    public function test_single_keyword_skips_synonyms_meta(): void
    {
        $product = (object) [
            'name' => 'Probe', 'sku' => 'SKU-1KW', 'active' => true, 'description' => '',
            'stock' => 0, 'manage_stock' => false, 'weight' => 0, 'width' => 0, 'height' => 0, 'depth' => 0,
            'price' => '[]', 'type' => 'product', 'meta_title' => '', 'meta_description' => '',
            'keywords' => json_encode(['solo'], JSON_UNESCAPED_UNICODE),
        ];

        $result = $this->transformer->transform($product);

        $meta = collect($result['meta_data']);
        $this->assertSame('solo', $meta->firstWhere('key', '_yoast_wpseo_focuskw')['value']);
        $this->assertNull($meta->firstWhere('key', '_yoast_wpseo_keywordsynonyms'));
    }

    public function test_visibility_30_becomes_visible(): void
    {
        $result = $this->transformer->transform($this->visibilityProduct(30));
        $this->assertSame('visible', $result['catalog_visibility']);
    }

    public function test_visibility_20_becomes_search(): void
    {
        $result = $this->transformer->transform($this->visibilityProduct(20));
        $this->assertSame('search', $result['catalog_visibility']);
    }

    public function test_visibility_10_link_only_becomes_hidden(): void
    {
        $result = $this->transformer->transform($this->visibilityProduct(10));
        $this->assertSame('hidden', $result['catalog_visibility']);
    }

    public function test_missing_visibility_row_becomes_hidden(): void
    {
        // Bug fix: WC defaults missing catalog_visibility to `visible`.
        $result = $this->transformer->transform($this->visibilityProduct(null));
        $this->assertSame('hidden', $result['catalog_visibility']);
    }

    public function test_visibility_zero_becomes_hidden(): void
    {
        $result = $this->transformer->transform($this->visibilityProduct(0));
        $this->assertSame('hidden', $result['catalog_visibility']);
    }

    public function test_transforms_inactive_product_to_draft(): void
    {
        $product = (object) [
            'name' => 'Inactive',
            'sku' => 'SKU-002',
            'active' => false,
            'description' => '',
            'stock' => 0,
            'manage_stock' => false,
            'weight' => 0,
            'width' => 0,
            'height' => 0,
            'depth' => 0,
            'price' => '[]',
            'type' => 'product',
            'meta_title' => '',
            'meta_description' => '',
        ];

        $result = $this->transformer->transform($product);

        $this->assertEquals('draft', $result['status']);
    }

    public function test_handles_sale_price_from_list_price(): void
    {
        $product = (object) [
            'name' => 'Sale Product',
            'sku' => 'SKU-003',
            'active' => true,
            'description' => '',
            'stock' => 5,
            'manage_stock' => false,
            'weight' => 0,
            'width' => 0,
            'height' => 0,
            'depth' => 0,
            'price' => json_encode([['gross' => 19.99, 'net' => 16.80, 'linked' => true, 'listPrice' => ['gross' => 29.99]]]),
            'type' => 'product',
            'meta_title' => '',
            'meta_description' => '',
        ];

        $result = $this->transformer->transform($product);

        $this->assertEquals('29.99', $result['regular_price']);
        $this->assertEquals('19.99', $result['sale_price']);
    }

    public function test_builds_variant_attributes(): void
    {
        $settings = [
            (object) ['group_name' => 'Color', 'option_name' => 'Red'],
            (object) ['group_name' => 'Color', 'option_name' => 'Blue'],
            (object) ['group_name' => 'Size', 'option_name' => 'M'],
            (object) ['group_name' => 'Size', 'option_name' => 'L'],
        ];

        $result = $this->transformer->buildAttributes($settings, true);

        $this->assertCount(2, $result);
        $this->assertEquals('Color', $result[0]['name']);
        $this->assertContains('Red', $result[0]['options']);
        $this->assertContains('Blue', $result[0]['options']);
        $this->assertTrue($result[0]['variation']);
        $this->assertEquals('Size', $result[1]['name']);
    }

    public function test_builds_variant_option_attributes(): void
    {
        $options = [
            (object) ['group_name' => 'Color', 'option_name' => 'Red'],
            (object) ['group_name' => 'Size', 'option_name' => 'M'],
        ];

        $result = $this->transformer->buildVariantOptionAttributes($options);

        $this->assertCount(2, $result);
        $this->assertEquals('Color', $result[0]['name']);
        $this->assertEquals('Red', $result[0]['option']);
    }

    public function test_transforms_variant(): void
    {
        $variant = (object) [
            'sku' => 'SKU-001-RED-M',
            'stock' => 3,
            'manage_stock' => true,
            'weight' => 500,
            'price' => json_encode([['gross' => 34.99, 'net' => 28.57, 'linked' => true]]),
        ];

        $result = $this->transformer->transformVariant($variant);

        $this->assertEquals('SKU-001-RED-M', $result['sku']);
        $this->assertEquals('34.99', $result['regular_price']);
        $this->assertEquals(3, $result['stock_quantity']);
        $this->assertEquals('0.5', $result['weight']);
    }

    public function test_variant_emits_menu_order_from_display_order(): void
    {
        $variant = (object) [
            'sku' => 'Remiza10896.2',
            'stock' => 5,
            'manage_stock' => true,
            'weight' => 0,
            'price' => '[]',
            'display_order' => 2,
        ];

        $result = $this->transformer->transformVariant($variant);

        $this->assertSame(2, $result['menu_order']);
    }

    public function test_variant_menu_order_defaults_to_zero_when_missing(): void
    {
        $variant = (object) [
            'sku' => 'SKU-NO-ORDER', 'stock' => 0, 'manage_stock' => false,
            'weight' => 0, 'price' => '[]',
        ];

        $result = $this->transformer->transformVariant($variant);

        $this->assertSame(0, $result['menu_order']);
    }

    /**
     * @return iterable<string, array{0:int,1:bool,2:bool,3:bool}>
     */
    public static function blockPurchaseRuleCases(): iterable
    {
        // stock, is_closeout (manage_stock), rule_enabled, expected_block
        yield 'rule off, closeout stockout' => [0, true, false, false];
        yield 'rule on, closeout stockout' => [0, true, true,  true];
        yield 'rule on, closeout in stock' => [5, true, true,  false];
        yield 'rule on, backorders stockout' => [0, false, true, false];
        yield 'rule on, negative stock' => [-1, true, true, true];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('blockPurchaseRuleCases')]
    public function test_should_block_purchase_rule(int $stock, bool $closeout, bool $ruleOn, bool $expected): void
    {
        $product = (object) ['stock' => $stock, 'manage_stock' => $closeout];

        $this->assertSame($expected, \App\Shopware\Transformers\ProductTransformer::shouldBlockPurchase($product, $ruleOn));
    }

    public function test_block_purchase_meta_emitted_on_parent_when_rule_matches(): void
    {
        $product = (object) [
            'name' => 'Czapka PSP', 'sku' => 'SKU-Czapka', 'active' => true,
            'description' => '', 'stock' => 0, 'manage_stock' => true,
            'weight' => 0, 'width' => 0, 'height' => 0, 'depth' => 0,
            'price' => '[]', 'type' => 'product', 'meta_title' => '', 'meta_description' => '',
        ];

        $result = $this->transformer->transform(
            $product, [], null, '', [], [], null, null, blockPurchaseRule: true
        );

        $hits = array_values(array_filter(
            $result['meta_data'],
            fn ($m) => $m['key'] === '_remizasklep_block_purchase'
        ));
        $this->assertCount(1, $hits);
        $this->assertSame('yes', $hits[0]['value']);
    }

    public function test_block_purchase_meta_omitted_when_rule_off_or_unmatched(): void
    {
        $product = (object) [
            'name' => 'In-stock', 'sku' => 'SKU-IS', 'active' => true,
            'description' => '', 'stock' => 10, 'manage_stock' => true,
            'weight' => 0, 'width' => 0, 'height' => 0, 'depth' => 0,
            'price' => '[]', 'type' => 'product', 'meta_title' => '', 'meta_description' => '',
        ];

        $result = $this->transformer->transform(
            $product, [], null, '', [], [], null, null, blockPurchaseRule: true
        );

        $hits = array_filter($result['meta_data'], fn ($m) => $m['key'] === '_remizasklep_block_purchase');
        $this->assertSame([], array_values($hits));
    }

    public function test_block_purchase_meta_on_variant(): void
    {
        $variant = (object) [
            'sku' => 'SKU-V', 'stock' => 0, 'manage_stock' => true,
            'weight' => 0, 'price' => '[]',
        ];

        $result = $this->transformer->transformVariant($variant, [], true);

        $hits = array_values(array_filter(
            $result['meta_data'] ?? [],
            fn ($m) => $m['key'] === '_remizasklep_block_purchase'
        ));
        $this->assertCount(1, $hits);
        $this->assertSame('yes', $hits[0]['value']);
    }

    public function test_handles_categories_and_tags(): void
    {
        $product = (object) [
            'name' => 'Product',
            'sku' => 'SKU-004',
            'active' => true,
            'description' => '',
            'stock' => 0,
            'manage_stock' => false,
            'weight' => 0,
            'width' => 0,
            'height' => 0,
            'depth' => 0,
            'price' => '[]',
            'type' => 'product',
            'meta_title' => '',
            'meta_description' => '',
        ];

        $result = $this->transformer->transform(
            $product,
            categoryWooIds: [10, 20],
            tags: ['new', 'featured'],
        );

        $this->assertCount(2, $result['categories']);
        $this->assertEquals(10, $result['categories'][0]['id']);
        $this->assertCount(2, $result['tags']);
        $this->assertEquals('new', $result['tags'][0]['name']);
    }

    public function test_maps_grouped_product_type(): void
    {
        $product = (object) [
            'name' => 'Grouped',
            'sku' => 'GRP-001',
            'active' => true,
            'description' => '',
            'stock' => 0,
            'manage_stock' => false,
            'weight' => 0,
            'width' => 0,
            'height' => 0,
            'depth' => 0,
            'price' => '[]',
            'type' => 'grouped',
            'meta_title' => '',
            'meta_description' => '',
        ];

        $result = $this->transformer->transform($product);

        $this->assertEquals('grouped', $result['type']);
    }

    private function makeProduct(array $overrides = []): object
    {
        return (object) array_merge([
            'name' => 'Stock Test',
            'sku' => 'STK-001',
            'active' => true,
            'description' => '',
            'stock' => 0,
            'manage_stock' => false,
            'available' => true,
            'weight' => 0,
            'width' => 0,
            'height' => 0,
            'depth' => 0,
            'price' => '[{"gross": 9.99}]',
            'type' => 'product',
            'meta_title' => '',
            'meta_description' => '',
        ], $overrides);
    }

    public function test_manage_stock_is_always_true_regardless_of_is_closeout(): void
    {
        // Reader aliases is_closeout AS manage_stock; both values must yield manage_stock=true
        // in WC since Shopware always tracks inventory.
        foreach ([false, true] as $isCloseout) {
            $result = $this->transformer->transform($this->makeProduct(['manage_stock' => $isCloseout]));
            $this->assertTrue($result['manage_stock'], "manage_stock should always be true (is_closeout={$isCloseout})");
        }
    }

    public function test_stock_status_with_is_closeout_false_keeps_selling_at_zero_stock(): void
    {
        $result = $this->transformer->transform($this->makeProduct([
            'manage_stock' => false, // is_closeout=false in Shopware → keep selling
            'stock' => 0,
            'available' => true,
        ]));

        $this->assertSame('instock', $result['stock_status']);
    }

    public function test_stock_status_with_is_closeout_true_goes_out_when_stock_zero(): void
    {
        $result = $this->transformer->transform($this->makeProduct([
            'manage_stock' => true, // is_closeout=true → stop selling at zero
            'stock' => 0,
            'available' => true,
        ]));

        $this->assertSame('outofstock', $result['stock_status']);
    }

    public function test_stock_status_respects_explicit_unavailable(): void
    {
        $result = $this->transformer->transform($this->makeProduct([
            'manage_stock' => false,
            'stock' => 5,
            'available' => false,
        ]));

        $this->assertSame('outofstock', $result['stock_status']);
    }

    public function test_regular_price_formatted_to_two_decimals(): void
    {
        $result = $this->transformer->transform($this->makeProduct([
            'price' => '[{"gross": 19.5}]',
        ]));

        // Plain round() yielded '19.5' before — WC's UI then renders inconsistent precision.
        $this->assertSame('19.50', $result['regular_price']);
    }

    public function test_sale_price_emitted_when_list_price_higher_than_gross(): void
    {
        $result = $this->transformer->transform($this->makeProduct([
            'price' => '[{"gross": 12, "listPrice": {"gross": 20}}]',
        ]));

        $this->assertSame('20.00', $result['regular_price']);
        $this->assertSame('12.00', $result['sale_price']);
    }

    public function test_no_sale_price_when_list_price_lower_or_equal(): void
    {
        // Regression guard for sale price downgrade: a list price lower than gross must
        // never be emitted as a "sale" — that would advertise a higher price as a discount.
        $result = $this->transformer->transform($this->makeProduct([
            'price' => '[{"gross": 20, "listPrice": {"gross": 15}}]',
        ]));

        $this->assertSame('20.00', $result['regular_price']);
        $this->assertArrayNotHasKey('sale_price', $result);
    }

    public function test_purchase_prices_emits_wc_cog_cost(): void
    {
        $product = $this->makeProduct([
            'purchase_prices' => '{"cb7d2554b0ce847cd82f3ac9bd1c0dfca":{"gross":12.5,"net":10.16,"linked":true,"currencyId":"b7d2554b0ce847cd82f3ac9bd1c0dfca"}}',
        ]);
        $result = $this->transformer->transform($product);
        $cost = collect($result['meta_data'])->firstWhere('key', '_wc_cog_cost');

        $this->assertNotNull($cost);
        $this->assertSame('12.50', $cost['value']);
    }

    public function test_purchase_prices_zero_or_null_skipped(): void
    {
        foreach (['{"x":{"gross":0}}', null, '', 'not-json'] as $v) {
            $product = $this->makeProduct(['purchase_prices' => $v]);
            $result = $this->transformer->transform($product);
            $hasCost = collect($result['meta_data'])->contains(fn ($m) => $m['key'] === '_wc_cog_cost');
            $this->assertFalse($hasCost, 'Should not emit _wc_cog_cost for value: '.var_export($v, true));
        }
    }

    public function test_primary_category_emitted_as_yoast_meta(): void
    {
        $result = $this->transformer->transform(
            $this->makeProduct(),
            primaryCategoryWooId: 42,
        );
        $meta = collect($result['meta_data'])->firstWhere('key', '_yoast_wpseo_primary_product_cat');

        $this->assertNotNull($meta);
        $this->assertSame('42', $meta['value']);
    }

    public function test_omnibus_lowest_price_emitted_when_provided(): void
    {
        $result = $this->transformer->transform(
            $this->makeProduct(),
            omnibusLowestPrice: '41',
        );
        $meta = collect($result['meta_data'])->firstWhere('key', '_omnibus_lowest_price');

        $this->assertNotNull($meta);
        $this->assertSame('41.00', $meta['value']);
    }

    public function test_omnibus_lowest_price_omitted_when_null(): void
    {
        $result = $this->transformer->transform($this->makeProduct());
        $hasOmnibus = collect($result['meta_data'])->contains(fn ($m) => $m['key'] === '_omnibus_lowest_price');

        $this->assertFalse($hasOmnibus);
    }

    public function test_release_date_emitted_when_present(): void
    {
        $product = $this->makeProduct(['release_date' => '2026-06-01 09:00:00']);
        $result = $this->transformer->transform($product);
        $meta = collect($result['meta_data'])->firstWhere('key', '_shopware_release_date');

        $this->assertNotNull($meta);
        $this->assertSame('2026-06-01 09:00:00', $meta['value']);
    }
}

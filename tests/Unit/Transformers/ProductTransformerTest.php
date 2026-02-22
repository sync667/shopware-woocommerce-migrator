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
}

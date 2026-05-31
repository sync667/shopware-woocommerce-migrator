<?php

namespace Tests\Unit\Transformers;

use App\Shopware\Transformers\SeoUrlTransformer;
use PHPUnit\Framework\TestCase;

class SeoUrlTransformerTest extends TestCase
{
    private SeoUrlTransformer $transformer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transformer = new SeoUrlTransformer;
    }

    public function test_transforms_product_with_slug(): void
    {
        $seoUrl = (object) [
            'id' => 'aaa111',
            'foreign_key' => 'bbb222',
            'route_name' => 'frontend.detail.page',
            'seo_path_info' => 'classic-shoe',
            'is_canonical' => 1,
        ];

        $result = $this->transformer->transform($seoUrl, 'product', 42, 'classic-shoe-wc');

        $this->assertSame('/classic-shoe', $result['source']);
        $this->assertSame('/product/classic-shoe-wc/', $result['target']);
        $this->assertSame(301, $result['code']);
        $this->assertSame('aaa111', $result['metadata']['shopware_id']);
        $this->assertSame('bbb222', $result['metadata']['foreign_key']);
        $this->assertSame('frontend.detail.page', $result['metadata']['route_name']);
        $this->assertTrue($result['metadata']['is_canonical']);
    }

    public function test_transforms_category_with_slug(): void
    {
        $seoUrl = (object) [
            'id' => 'a',
            'foreign_key' => 'b',
            'route_name' => 'frontend.navigation.page',
            'seo_path_info' => 'Shoes/Sneakers',
            'is_canonical' => 1,
        ];

        $result = $this->transformer->transform($seoUrl, 'category', 7, 'sneakers');

        $this->assertSame('/Shoes/Sneakers', $result['source']);
        $this->assertSame('/product-category/sneakers/', $result['target']);
    }

    public function test_transforms_cms_page_with_slug(): void
    {
        $seoUrl = (object) [
            'id' => 'a',
            'foreign_key' => 'b',
            'route_name' => 'frontend.cms.page',
            'seo_path_info' => 'help/shipping',
            'is_canonical' => 1,
        ];

        $result = $this->transformer->transform($seoUrl, 'cms_page', 99, 'shipping');

        $this->assertSame('/help/shipping', $result['source']);
        $this->assertSame('/shipping/', $result['target']);
    }

    public function test_product_fallback_when_slug_missing(): void
    {
        $seoUrl = (object) [
            'id' => 'a',
            'foreign_key' => 'b',
            'route_name' => 'frontend.detail.page',
            'seo_path_info' => 'thing',
            'is_canonical' => 0,
        ];

        $result = $this->transformer->transform($seoUrl, 'product', 42, null);

        $this->assertSame('/?p=42', $result['target']);
        $this->assertFalse($result['metadata']['is_canonical']);
    }

    public function test_category_fallback_when_slug_missing(): void
    {
        $seoUrl = (object) [
            'id' => 'a',
            'foreign_key' => 'b',
            'route_name' => 'frontend.navigation.page',
            'seo_path_info' => 'x',
            'is_canonical' => 1,
        ];

        $result = $this->transformer->transform($seoUrl, 'category', 7, null);

        $this->assertSame('/?cat=7', $result['target']);
    }

    public function test_cms_page_fallback_when_slug_missing(): void
    {
        $seoUrl = (object) [
            'id' => 'a',
            'foreign_key' => 'b',
            'route_name' => 'frontend.cms.page',
            'seo_path_info' => 'x',
            'is_canonical' => 1,
        ];

        $result = $this->transformer->transform($seoUrl, 'cms_page', 99, null);

        $this->assertSame('/?page_id=99', $result['target']);
    }

    public function test_source_normalization_leading_slash(): void
    {
        $seoUrl = (object) [
            'id' => 'a', 'foreign_key' => 'b', 'route_name' => 'frontend.detail.page',
            'seo_path_info' => 'no-leading-slash', 'is_canonical' => 1,
        ];

        $result = $this->transformer->transform($seoUrl, 'product', 1, 'slug');

        $this->assertSame('/no-leading-slash', $result['source']);
    }

    public function test_source_normalization_strips_trailing_slash(): void
    {
        $seoUrl = (object) [
            'id' => 'a', 'foreign_key' => 'b', 'route_name' => 'frontend.detail.page',
            'seo_path_info' => '/has-trailing/', 'is_canonical' => 1,
        ];

        $result = $this->transformer->transform($seoUrl, 'product', 1, 'slug');

        $this->assertSame('/has-trailing', $result['source']);
    }

    public function test_source_normalization_collapses_double_slashes(): void
    {
        $seoUrl = (object) [
            'id' => 'a', 'foreign_key' => 'b', 'route_name' => 'frontend.detail.page',
            'seo_path_info' => '//doubled//path//here', 'is_canonical' => 1,
        ];

        $result = $this->transformer->transform($seoUrl, 'product', 1, 'slug');

        $this->assertSame('/doubled/path/here', $result['source']);
    }

    public function test_source_normalization_strips_query_string(): void
    {
        $seoUrl = (object) [
            'id' => 'a', 'foreign_key' => 'b', 'route_name' => 'frontend.detail.page',
            'seo_path_info' => 'page?utm=x&y=z', 'is_canonical' => 1,
        ];

        $result = $this->transformer->transform($seoUrl, 'product', 1, 'slug');

        $this->assertSame('/page', $result['source']);
    }

    public function test_self_redirect_detection(): void
    {
        $seoUrl = (object) [
            'id' => 'a', 'foreign_key' => 'b', 'route_name' => 'frontend.detail.page',
            'seo_path_info' => 'product/same', 'is_canonical' => 1,
        ];

        $result = $this->transformer->transform($seoUrl, 'product', 1, 'same');

        $this->assertSame('/product/same', $result['source']);
        $this->assertSame('/product/same/', $result['target']);
        $this->assertTrue($result['is_self_redirect']);
    }

    public function test_not_self_redirect_when_different(): void
    {
        $seoUrl = (object) [
            'id' => 'a', 'foreign_key' => 'b', 'route_name' => 'frontend.detail.page',
            'seo_path_info' => 'old-slug', 'is_canonical' => 1,
        ];

        $result = $this->transformer->transform($seoUrl, 'product', 1, 'new-slug');

        $this->assertFalse($result['is_self_redirect']);
    }

    public function test_throws_on_unknown_entity_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $seoUrl = (object) [
            'id' => 'a', 'foreign_key' => 'b', 'route_name' => 'frontend.unknown',
            'seo_path_info' => 'x', 'is_canonical' => 1,
        ];

        $this->transformer->transform($seoUrl, 'unknown', 1, 'slug');
    }

    public function test_source_percent_encodes_non_ascii_segments(): void
    {
        $seoUrl = (object) [
            'id' => 'a', 'foreign_key' => 'b', 'route_name' => 'frontend.detail.page',
            'seo_path_info' => 'obuwie-skórzane', 'is_canonical' => 1,
        ];

        $result = $this->transformer->transform($seoUrl, 'product', 9, 'leather-shoe');

        // Browsers send '/obuwie-sk%C3%B3rzane' for this path. Storing the raw UTF-8
        // form in Redirection would never match the incoming request.
        $this->assertSame('/obuwie-sk%C3%B3rzane', $result['source']);
    }

    public function test_source_percent_encodes_spaces(): void
    {
        $seoUrl = (object) [
            'id' => 'a', 'foreign_key' => 'b', 'route_name' => 'frontend.navigation.page',
            'seo_path_info' => 'kategoria/damen schuhe', 'is_canonical' => 1,
        ];

        $result = $this->transformer->transform($seoUrl, 'category', 9, 'damen-schuhe');

        $this->assertSame('/kategoria/damen%20schuhe', $result['source']);
    }

    public function test_source_preserves_already_encoded_segments(): void
    {
        $seoUrl = (object) [
            'id' => 'a', 'foreign_key' => 'b', 'route_name' => 'frontend.detail.page',
            'seo_path_info' => 'obuwie-sk%C3%B3rzane', 'is_canonical' => 1,
        ];

        $result = $this->transformer->transform($seoUrl, 'product', 9, 'leather-shoe');

        // Idempotent: a path that's already percent-encoded must not be re-encoded
        // (otherwise '%' becomes '%25' and the redirect breaks).
        $this->assertSame('/obuwie-sk%C3%B3rzane', $result['source']);
    }
}

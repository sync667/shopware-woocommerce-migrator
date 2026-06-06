<?php

namespace Tests\Unit\Transformers;

use App\Shopware\Transformers\CategoryTransformer;
use PHPUnit\Framework\TestCase;

class CategoryTransformerTest extends TestCase
{
    public function test_transforms_category(): void
    {
        $transformer = new CategoryTransformer;

        $category = (object) [
            'name' => 'Electronics',
            'description' => 'All electronics',
            'sort_order' => 5,
        ];

        $result = $transformer->transform($category);

        $this->assertEquals('Electronics', $result['name']);
        $this->assertEquals('All electronics', $result['description']);
        $this->assertEquals(5, $result['menu_order']);
        $this->assertArrayNotHasKey('parent', $result);
    }

    public function test_transforms_child_category(): void
    {
        $transformer = new CategoryTransformer;

        $category = (object) [
            'name' => 'Laptops',
            'description' => '',
            'sort_order' => 2,
        ];

        $result = $transformer->transform($category, 42);

        $this->assertEquals(42, $result['parent']);
    }

    public function test_empty_seo_text_leaves_description_unchanged(): void
    {
        $transformer = new CategoryTransformer;

        $category = (object) [
            'name' => 'Books',
            'description' => 'Existing description text',
            'sort_order' => 1,
        ];

        $result = $transformer->transform($category, null, '');

        $this->assertSame('Existing description text', $result['description']);
    }

    public function test_seo_text_appended_to_existing_description_with_marker(): void
    {
        $transformer = new CategoryTransformer;

        $category = (object) [
            'name' => 'Books',
            'description' => 'Short description',
            'sort_order' => 1,
        ];

        $result = $transformer->transform($category, null, 'Long SEO copy goes here');

        $expected = "Short description\n\n<!-- shopware-seo-text -->\nLong SEO copy goes here";
        $this->assertSame($expected, $result['description']);
    }

    public function test_seo_text_used_alone_when_description_empty(): void
    {
        $transformer = new CategoryTransformer;

        $category = (object) [
            'name' => 'Books',
            'description' => '',
            'sort_order' => 1,
        ];

        $result = $transformer->transform($category, null, 'Long SEO copy');

        $expected = "<!-- shopware-seo-text -->\nLong SEO copy";
        $this->assertSame($expected, $result['description']);
    }

    public function test_transform_is_idempotent_for_same_inputs(): void
    {
        $transformer = new CategoryTransformer;

        $category = (object) [
            'id' => 'cat-1',
            'name' => 'Books',
            'description' => 'Short description',
            'sort_order' => 1,
            'meta_title' => 'Books — Title',
            'meta_description' => 'Books meta',
        ];

        $first = $transformer->transform($category, 7, 'SEO copy');
        $second = $transformer->transform($category, 7, 'SEO copy');

        $this->assertSame($first, $second);
        $this->assertSame(
            "Short description\n\n<!-- shopware-seo-text -->\nSEO copy",
            $first['description']
        );
    }

    public function test_emits_yoast_meta_title_and_description(): void
    {
        $transformer = new CategoryTransformer;

        $category = (object) [
            'id' => 'cat-1',
            'name' => 'Books',
            'description' => '',
            'sort_order' => 1,
            'meta_title' => 'Books — Title',
            'meta_description' => 'Books meta description',
        ];

        $result = $transformer->transform($category);

        $keys = array_column($result['meta_data'], 'key');
        $this->assertContains('_yoast_wpseo_title', $keys);
        $this->assertContains('_yoast_wpseo_metadesc', $keys);

        $byKey = [];
        foreach ($result['meta_data'] as $row) {
            $byKey[$row['key']] = $row['value'];
        }
        $this->assertSame('Books — Title', $byKey['_yoast_wpseo_title']);
        $this->assertSame('Books meta description', $byKey['_yoast_wpseo_metadesc']);
        $this->assertSame('cat-1', $byKey['_shopware_category_id']);
    }

    public function test_description_runs_through_content_migrator_when_injected(): void
    {
        $contentMigrator = $this->fakeContentMigrator();
        $transformer = new CategoryTransformer($contentMigrator);

        $category = (object) [
            'name' => 'Books',
            'description' => '<p>Books with <font color="#e52413">red brand text</font> and <kbd>Ctrl</kbd></p><script>evil()</script>',
            'sort_order' => 0,
        ];

        $result = $transformer->transform($category);

        $this->assertStringContainsString('<font color="#e52413">', $result['description']);
        $this->assertStringContainsString('<kbd>Ctrl</kbd>', $result['description']);
        $this->assertStringNotContainsString('<script', $result['description']);
    }

    public function test_seo_text_below_is_also_sanitized(): void
    {
        $contentMigrator = $this->fakeContentMigrator();
        $transformer = new CategoryTransformer($contentMigrator);

        $category = (object) [
            'name' => 'Books',
            'description' => '',
            'sort_order' => 0,
        ];

        $result = $transformer->transform(
            $category,
            null,
            '<p onclick="alert(1)">SEO copy</p><style>.evil{}</style>'
        );

        $this->assertStringContainsString('<p>SEO copy</p>', $result['description']);
        $this->assertStringNotContainsString('onclick', $result['description']);
        $this->assertStringNotContainsString('<style', $result['description']);
    }

    public function test_description_unchanged_when_no_content_migrator_injected(): void
    {
        // The default DI null path is preserved so the existing unit tests above
        // (which construct the transformer with no args) keep working.
        $transformer = new CategoryTransformer;

        $category = (object) [
            'name' => 'Books',
            'description' => '<p>raw</p>',
            'sort_order' => 0,
        ];

        $result = $transformer->transform($category);

        $this->assertSame('<p>raw</p>', $result['description']);
    }

    /** Builds a real ContentMigrator with a no-op WP media stub. */
    private function fakeContentMigrator(): \App\Services\ContentMigrator
    {
        $wpMedia = new \App\Services\WordPressMediaClient([
            'base_url' => 'https://example.test',
            'wp_username' => 'x',
            'wp_app_password' => 'x',
        ]);
        $imageMigrator = new \App\Services\ImageMigrator($wpMedia, 'https://shop.test');

        return new \App\Services\ContentMigrator($imageMigrator);
    }

    public function test_omits_yoast_meta_when_fields_empty(): void
    {
        $transformer = new CategoryTransformer;

        $category = (object) [
            'id' => 'cat-1',
            'name' => 'Books',
            'description' => '',
            'sort_order' => 1,
            'meta_title' => '',
            'meta_description' => '',
        ];

        $result = $transformer->transform($category);

        $keys = array_column($result['meta_data'], 'key');
        $this->assertNotContains('_yoast_wpseo_title', $keys);
        $this->assertNotContains('_yoast_wpseo_metadesc', $keys);
    }
}

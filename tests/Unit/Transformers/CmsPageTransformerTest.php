<?php

namespace Tests\Unit\Transformers;

use App\Services\ContentMigrator;
use App\Services\ImageMigrator;
use App\Services\StateManager;
use App\Services\WordPressMediaClient;
use App\Shopware\Transformers\CmsPageTransformer;
use PHPUnit\Framework\TestCase;

class CmsPageTransformerTest extends TestCase
{
    public function test_text_slot_with_block_markup_no_longer_double_wraps_in_p(): void
    {
        $transformer = $this->makeTransformer();
        $page = (object) ['id' => 'pg-1', 'name' => 'About', 'type' => 'page'];

        $sections = [
            (object) ['blocks' => [
                (object) ['slots' => [
                    (object) [
                        'type' => 'text',
                        'config' => ['content' => ['value' => '<p>Already a paragraph</p>']],
                    ],
                ]],
            ]],
        ];

        $result = $transformer->transform($page, $sections);

        // Before the fix this produced <!-- wp:paragraph --><p><p>...</p></p>.
        $this->assertStringNotContainsString('<p><p>', $result['content']);
        // Block-level content lands in an HTML block, not a paragraph block.
        $this->assertStringContainsString('<!-- wp:html -->', $result['content']);
        $this->assertStringContainsString('<p>Already a paragraph</p>', $result['content']);
    }

    public function test_text_slot_preserves_legacy_font_and_strips_scripts(): void
    {
        $transformer = $this->makeTransformer();
        $page = (object) ['id' => 'pg-1', 'name' => 'About', 'type' => 'page'];

        $sections = [
            (object) ['blocks' => [
                (object) ['slots' => [
                    (object) [
                        'type' => 'text',
                        'config' => ['content' => ['value' => '<p>Hello <font color="#e52413">red</font></p><script>x()</script>']],
                    ],
                ]],
            ]],
        ];

        $result = $transformer->transform($page, $sections);

        $this->assertStringContainsString('<font color="#e52413">red</font>', $result['content']);
        $this->assertStringNotContainsString('<script', $result['content']);
    }

    public function test_html_slot_now_also_sanitizes(): void
    {
        // Old code passed Shopware HTML straight through, which bypassed
        // dangerous-attribute scrubbing AND skipped image-URL migration.
        $transformer = $this->makeTransformer();
        $page = (object) ['id' => 'pg-1', 'name' => 'About', 'type' => 'page'];

        $sections = [
            (object) ['blocks' => [
                (object) ['slots' => [
                    (object) [
                        'type' => 'html',
                        'config' => ['content' => ['value' => '<div onclick="alert(1)">click</div>']],
                    ],
                ]],
            ]],
        ];

        $result = $transformer->transform($page, $sections);

        $this->assertStringNotContainsString('onclick', $result['content']);
        $this->assertStringContainsString('<div>click</div>', $result['content']);
    }

    public function test_text_slot_plain_text_still_uses_paragraph_block(): void
    {
        $transformer = $this->makeTransformer();
        $page = (object) ['id' => 'pg-1', 'name' => 'About', 'type' => 'page'];

        $sections = [
            (object) ['blocks' => [
                (object) ['slots' => [
                    (object) [
                        'type' => 'text',
                        'config' => ['content' => ['value' => 'just plain text']],
                    ],
                ]],
            ]],
        ];

        $result = $transformer->transform($page, $sections);

        $this->assertStringContainsString('<!-- wp:paragraph -->', $result['content']);
        $this->assertStringContainsString('<p>just plain text</p>', $result['content']);
    }

    private function makeTransformer(): CmsPageTransformer
    {
        $wpMedia = new WordPressMediaClient([
            'base_url' => 'https://example.test',
            'wp_username' => 'x',
            'wp_app_password' => 'x',
        ]);
        $imageMigrator = new ImageMigrator($wpMedia, 'https://shop.test');
        $contentMigrator = new ContentMigrator($imageMigrator);

        return new CmsPageTransformer(new StateManager, 1, $contentMigrator);
    }
}

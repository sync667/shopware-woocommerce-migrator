<?php

namespace App\Shopware\Transformers;

use App\Services\ContentMigrator;
use App\Services\StateManager;

class CmsPageTransformer
{
    public function __construct(
        protected StateManager $stateManager,
        protected int $migrationId,
        protected ?ContentMigrator $contentMigrator = null
    ) {}

    /**
     * Transform CMS page to WordPress page
     */
    public function transform(
        object $page,
        array $sections = [],
        string $seoUrl = ''
    ): array {
        $content = $this->buildGutenbergContent($sections);

        return [
            'title' => $page->name ?: 'Untitled Page',
            'content' => $content,
            'status' => 'publish',
            'type' => 'page',
            'slug' => $this->generateSlug($page->name, $seoUrl),
            'meta_data' => [
                ['key' => '_shopware_cms_page_id', 'value' => $page->id],
                ['key' => '_shopware_cms_type', 'value' => $page->type],
                ['key' => '_migrated_at', 'value' => now()->toIso8601String()],
            ],
        ];
    }

    /**
     * Build Gutenberg block content from Shopware CMS structure
     */
    protected function buildGutenbergContent(array $sections): string
    {
        $blocks = [];

        foreach ($sections as $section) {
            foreach ($section->blocks ?? [] as $block) {
                foreach ($block->slots ?? [] as $slot) {
                    $transformedBlock = $this->transformSlot($slot);
                    if ($transformedBlock) {
                        $blocks[] = $transformedBlock;
                    }
                }
            }
        }

        return implode("\n\n", array_filter($blocks));
    }

    /**
     * Transform individual slot to Gutenberg block
     */
    protected function transformSlot(object $slot): ?string
    {
        $config = is_string($slot->config ?? null)
            ? json_decode($slot->config, true)
            : ($slot->config ?? []);

        if (! is_array($config)) {
            $config = [];
        }

        return match ($slot->type) {
            'text' => $this->transformTextSlot($config),
            'image' => $this->transformImageSlot($config),
            'html' => $this->transformHtmlSlot($config),
            'product-slider' => $this->transformProductSlider($config),
            'category-navigation' => $this->transformCategoryNavigation($config),
            'product-listing' => $this->transformProductListing($config),
            'manufacturer-logo' => $this->transformManufacturerLogo($config),
            default => $this->transformDefaultSlot($slot->type, $config),
        };
    }

    /**
     * Transform text slot to Gutenberg paragraph
     */
    protected function transformTextSlot(array $config): ?string
    {
        $content = $config['content']['value'] ?? '';

        if (empty($content)) {
            return null;
        }

        $content = $this->sanitize($content);

        // Don't wrap in <p> when the content already has block-level markup
        // (Shopware text slots commonly carry <p>, <div>, <table>, etc.). The
        // old code unconditionally wrapped and produced nested <p><p>foo</p></p>
        // which browsers auto-closed into broken structure.
        if (preg_match('/<(p|div|table|ul|ol|blockquote|h[1-6]|figure|section|article|details)\b/i', $content)) {
            return "<!-- wp:html -->\n{$content}\n<!-- /wp:html -->";
        }

        return "<!-- wp:paragraph -->\n<p>{$content}</p>\n<!-- /wp:paragraph -->";
    }

    /**
     * Transform image slot to Gutenberg image block
     */
    protected function transformImageSlot(array $config): ?string
    {
        // For now, create a placeholder
        // In production, would download and upload image to WordPress
        $mediaUrl = $config['media']['value'] ?? '';
        $url = $config['url']['value'] ?? '';
        $alt = $config['displayMode']['value'] ?? '';

        if (empty($mediaUrl)) {
            return null;
        }

        // Simplified version - just include the image URL
        // Full implementation would download and re-host the image
        return "<!-- wp:image -->\n".
               '<figure class="wp-block-image">'.
               "<img src=\"{$mediaUrl}\" alt=\"{$alt}\"/>".
               "</figure>\n".
               '<!-- /wp:image -->';
    }

    /**
     * Transform HTML slot
     */
    protected function transformHtmlSlot(array $config): ?string
    {
        $content = $config['content']['value'] ?? '';

        if (empty($content)) {
            return null;
        }

        $content = $this->sanitize($content);

        return "<!-- wp:html -->\n{$content}\n<!-- /wp:html -->";
    }

    /**
     * Transform product slider to WooCommerce shortcode
     */
    protected function transformProductSlider(array $config): ?string
    {
        $productIds = $config['products']['value'] ?? [];

        if (empty($productIds)) {
            return null;
        }

        $wcProductIds = [];
        foreach ($productIds as $shopwareId) {
            $wcId = $this->stateManager->get('product', $shopwareId, $this->migrationId);
            if ($wcId) {
                $wcProductIds[] = $wcId;
            }
        }

        if (empty($wcProductIds)) {
            return null;
        }

        $ids = implode(',', $wcProductIds);

        return "<!-- wp:shortcode -->\n[products ids=\"{$ids}\"]\n<!-- /wp:shortcode -->";
    }

    /**
     * Transform category navigation
     */
    protected function transformCategoryNavigation(array $config): ?string
    {
        return "<!-- wp:shortcode -->\n[product_categories]\n<!-- /wp:shortcode -->";
    }

    /**
     * Transform product listing
     */
    protected function transformProductListing(array $config): ?string
    {
        return "<!-- wp:shortcode -->\n[products limit=\"12\"]\n<!-- /wp:shortcode -->";
    }

    /**
     * Transform manufacturer logo
     */
    protected function transformManufacturerLogo(array $config): ?string
    {
        $mediaUrl = $config['media']['value'] ?? '';

        if (empty($mediaUrl)) {
            return null;
        }

        return "<!-- wp:image -->\n".
               '<figure class="wp-block-image">'.
               "<img src=\"{$mediaUrl}\" alt=\"Manufacturer Logo\"/>".
               "</figure>\n".
               '<!-- /wp:image -->';
    }

    /**
     * Generate WordPress slug from page name and SEO URL
     */
    protected function generateSlug(string $name, string $seoUrl): string
    {
        if ($seoUrl) {
            $slug = trim($seoUrl, '/');
            $slug = str_replace('/', '-', $slug);

            return sanitize_title($slug);
        }

        return sanitize_title($name);
    }

    /**
     * Sanitize via the shared ContentMigrator when available. Falls back to a
     * pass-through when the transformer was constructed without one (some unit
     * tests do this) — those tests don't assert on description content.
     */
    protected function sanitize(string $html): string
    {
        return $this->contentMigrator?->processHtmlContent($html) ?? $html;
    }

    /**
     * Default slot transformer for unknown types
     */
    protected function transformDefaultSlot(string $type, array $config): ?string
    {
        if (app()->environment('local')) {
            logger()->warning("Unknown CMS slot type: {$type}", ['config' => $config]);
        }

        return "<!-- Unknown Shopware CMS slot type: {$type} -->";
    }
}

/**
 * Helper function to sanitize title (WordPress-style)
 */
if (! function_exists('sanitize_title')) {
    function sanitize_title(string $title): string
    {
        $title = strtolower($title);
        $title = preg_replace('/[^a-z0-9-]+/', '-', $title);
        $title = trim($title, '-');

        return $title;
    }
}

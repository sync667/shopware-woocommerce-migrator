<?php

namespace App\Services;

use DOMDocument;
use DOMXPath;

class ContentMigrator
{
    public function __construct(
        protected ImageMigrator $imageMigrator
    ) {}

    /**
     * Process HTML content and migrate all embedded media
     */
    public function processHtmlContent(string $html): string
    {
        if (empty($html)) {
            return '';
        }

        // Remove script and style tags with their content (security)
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html);
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html);

        $dom = new DOMDocument('1.0', 'UTF-8');

        libxml_use_internal_errors(true);

        $wrappedHtml = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>'.$html.'</body></html>';
        $dom->loadHTML($wrappedHtml, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        libxml_clear_errors();

        $xpath = new DOMXPath($dom);

        $this->processImages($xpath);
        $this->processIframes($xpath);
        $this->removeUnsafeNodes($xpath);
        $this->stripDangerousAttributes($xpath);

        $body = $dom->getElementsByTagName('body')->item(0);
        if (! $body) {
            return $html; // Return original if parsing failed
        }

        $processedHtml = '';
        foreach ($body->childNodes as $node) {
            $processedHtml .= $dom->saveHTML($node);
        }

        return trim($processedHtml);
    }

    /**
     * Drop tags that have no business in a product description: scripting,
     * embedded plugins, form controls, head-only elements. Everything else —
     * including legacy formatting tags like font, center, mark, kbd, details —
     * is kept so Shopware's authored styling survives.
     */
    protected function removeUnsafeNodes(DOMXPath $xpath): void
    {
        $disallowed = ['script', 'style', 'object', 'embed', 'link', 'meta', 'base', 'noscript'];
        $expression = '//'.implode('|//', $disallowed);
        foreach (iterator_to_array($xpath->query($expression)) as $node) {
            $node->parentNode?->removeChild($node);
        }

        foreach (iterator_to_array($xpath->query('//*[@contenteditable="false"]')) as $node) {
            $node->parentNode?->removeChild($node);
        }
    }

    /**
     * Strip event handlers and javascript: URLs from every node. Preserves
     * class, style, id, data-* and similar formatting attributes.
     */
    protected function stripDangerousAttributes(DOMXPath $xpath): void
    {
        foreach ($xpath->query('//*[@*]') as $node) {
            $toRemove = [];
            foreach ($node->attributes as $attr) {
                $name = strtolower($attr->name);
                $value = $attr->value;
                if (str_starts_with($name, 'on')) {
                    $toRemove[] = $attr->name;

                    continue;
                }
                if (in_array($name, ['href', 'src', 'xlink:href'], true) && stripos(ltrim($value), 'javascript:') === 0) {
                    $node->setAttribute($attr->name, '#');
                }
            }
            foreach ($toRemove as $name) {
                $node->removeAttribute($name);
            }
        }
    }

    /**
     * Process all images in the HTML content
     */
    protected function processImages(DOMXPath $xpath): void
    {
        $images = $xpath->query('//img');

        foreach ($images as $img) {
            $srcAttr = $img->getAttribute('src');

            if (empty($srcAttr)) {
                continue;
            }

            if ($this->isShopwareMediaUrl($srcAttr)) {
                $wpImageId = $this->imageMigrator->migrateFromUrl($srcAttr);

                if ($wpImageId) {
                    $wpImageUrl = $this->imageMigrator->getWordPressMediaUrl($wpImageId);

                    if ($wpImageUrl) {
                        $img->setAttribute('src', $wpImageUrl);

                        if (! $img->hasAttribute('alt')) {
                            $img->setAttribute('alt', '');
                        }

                        $existingClass = $img->getAttribute('class');
                        $newClass = trim($existingClass.' alignnone size-full wp-image-'.$wpImageId);
                        $img->setAttribute('class', $newClass);
                    }
                }
            }
        }
    }

    /**
     * Process iframes (videos, embeds)
     */
    protected function processIframes(DOMXPath $xpath): void
    {
        $iframes = $xpath->query('//iframe');

        foreach ($iframes as $iframe) {
            $src = $iframe->getAttribute('src');

            if ($this->isVideoEmbed($src)) {
                $iframe->setAttribute('loading', 'lazy');

                if (! $iframe->hasAttribute('width')) {
                    $iframe->setAttribute('width', '560');
                }
                if (! $iframe->hasAttribute('height')) {
                    $iframe->setAttribute('height', '315');
                }
            }
        }
    }

    /**
     * Check if URL is a Shopware media URL
     */
    protected function isShopwareMediaUrl(string $url): bool
    {
        return str_contains($url, '/media/')
            || str_contains($url, '/thumbnail/')
            || preg_match('/\.(jpg|jpeg|png|gif|webp|svg)$/i', $url);
    }

    /**
     * Check if URL is a video embed
     */
    protected function isVideoEmbed(string $url): bool
    {
        return str_contains($url, 'youtube.com')
            || str_contains($url, 'youtu.be')
            || str_contains($url, 'vimeo.com')
            || str_contains($url, 'dailymotion.com');
    }

    /**
     * Extract plain text from HTML (for short descriptions)
     */
    public function extractPlainText(string $html, int $maxLength = 150): string
    {
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);

        if (mb_strlen($text) > $maxLength) {
            $text = mb_substr($text, 0, $maxLength).'...';
        }

        return $text;
    }

    /**
     * Check if HTML contains rich content (tables, lists, etc.)
     */
    public function hasRichContent(string $html): bool
    {
        return preg_match('/<(table|ul|ol|iframe|img|video)[\s>]/i', $html) === 1;
    }
}

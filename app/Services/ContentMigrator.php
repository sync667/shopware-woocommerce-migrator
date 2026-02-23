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

        // Load HTML into DOM
        $dom = new DOMDocument('1.0', 'UTF-8');

        // Suppress warnings for malformed HTML
        libxml_use_internal_errors(true);

        // Wrap content to handle fragments properly
        $wrappedHtml = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>'.$html.'</body></html>';
        $dom->loadHTML($wrappedHtml, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        libxml_clear_errors();

        $xpath = new DOMXPath($dom);

        // Process all images
        $this->processImages($xpath);

        // Process iframes (videos, embeds)
        $this->processIframes($xpath);

        // Extract body content
        $body = $dom->getElementsByTagName('body')->item(0);
        if (! $body) {
            return $html; // Return original if parsing failed
        }

        $processedHtml = '';
        foreach ($body->childNodes as $node) {
            $processedHtml .= $dom->saveHTML($node);
        }

        return $this->cleanHtml($processedHtml);
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

            // Check if it's a Shopware media URL
            if ($this->isShopwareMediaUrl($srcAttr)) {
                $wpImageId = $this->imageMigrator->migrateFromUrl($srcAttr);

                if ($wpImageId) {
                    // Get WordPress media URL
                    $wpImageUrl = $this->imageMigrator->getWordPressMediaUrl($wpImageId);

                    if ($wpImageUrl) {
                        $img->setAttribute('src', $wpImageUrl);

                        // Preserve alt text
                        if (! $img->hasAttribute('alt')) {
                            $img->setAttribute('alt', '');
                        }

                        // Add class for WordPress styling
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

            // Add responsive wrapper for video embeds
            if ($this->isVideoEmbed($src)) {
                $iframe->setAttribute('loading', 'lazy');

                // Ensure iframe has proper attributes for responsive embeds
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
        // Check for common Shopware media patterns
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
     * Clean and sanitize HTML while preserving formatting
     */
    protected function cleanHtml(string $html): string
    {
        // Allowed HTML tags for WooCommerce product descriptions
        $allowedTags = [
            'p', 'br', 'strong', 'b', 'em', 'i', 'u', 'strike', 's',
            'a', 'ul', 'ol', 'li',
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
            'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td',
            'div', 'span', 'blockquote', 'pre', 'code',
            'img', 'figure', 'figcaption',
            'iframe', 'video', 'audio', 'source',
            'hr', 'sup', 'sub',
        ];

        // Build allowed tags string
        $allowedTagsStr = '<'.implode('><', $allowedTags).'>';

        // Strip disallowed tags but keep content
        $html = strip_tags($html, $allowedTagsStr);

        // Remove potentially dangerous attributes (except on safe elements)
        $html = $this->removeDangerousAttributes($html);

        return $html;
    }

    /**
     * Remove potentially dangerous HTML attributes
     */
    protected function removeDangerousAttributes(string $html): string
    {
        // Remove onclick, onerror, onload, etc.
        $html = preg_replace('/\s*on\w+\s*=\s*["\'][^"\']*["\']/i', '', $html);

        // Remove javascript: protocol
        $html = preg_replace('/href\s*=\s*["\']javascript:[^"\']*["\']/i', 'href="#"', $html);

        return $html;
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

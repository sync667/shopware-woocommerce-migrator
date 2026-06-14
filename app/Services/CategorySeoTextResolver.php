<?php

namespace App\Services;

class CategorySeoTextResolver
{
    public function __construct(protected ShopwareDB $db) {}

    /**
     * Resolve the long-form SEO copy for a category.
     *
     * Priority order:
     *   1. Configured custom-field key from category_translation.custom_fields JSON
     *   2. Text/HTML slot content from the linked CMS page (last slot wins)
     *   3. Empty string when no source has data
     */
    public function resolve(object $category): string
    {
        $fromCustomField = $this->resolveFromCustomField($category);
        if ($fromCustomField !== '') {
            return $this->renderPlaceholders($fromCustomField, $category);
        }

        return $this->renderPlaceholders($this->resolveFromCmsPage($category), $category);
    }

    /**
     * Render Shopware Twig placeholders ({{ category.X }} and
     * {{ category.translated.X }}) against the category context. Unknown
     * tokens are stripped rather than passed through so raw Twig never
     * leaks into the migrated WC term description.
     */
    protected function renderPlaceholders(string $source, object $category): string
    {
        if ($source === '' || ! str_contains($source, '{{')) {
            return $source;
        }

        $values = [
            'name' => (string) ($category->name ?? ''),
            'metatitle' => (string) ($category->meta_title ?? ''),
            'metadescription' => (string) ($category->meta_description ?? ''),
            'keywords' => (string) ($category->keywords ?? ''),
        ];

        return (string) preg_replace_callback(
            '/\{\{\s*category(?:\.translated)?\.([A-Za-z_]+)\s*\}\}/u',
            static function (array $m) use ($values): string {
                $key = strtolower($m[1]);

                return $values[$key] ?? '';
            },
            $source
        );
    }

    protected function resolveFromCustomField(object $category): string
    {
        $key = (string) config('migration.category_seo.custom_field_key', 'custom_seo_text_below');
        if ($key === '') {
            return '';
        }

        $raw = $category->translation_custom_fields ?? null;
        if (! is_string($raw) || $raw === '') {
            return '';
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return '';
        }

        $value = $decoded[$key] ?? null;
        if (! is_string($value)) {
            return '';
        }

        return trim($value) === '' ? '' : $value;
    }

    protected function resolveFromCmsPage(object $category): string
    {
        $cmsPageId = $category->cms_page_id ?? '';
        if (! is_string($cmsPageId) || $cmsPageId === '') {
            return '';
        }

        try {
            // Filter by version_id so draft slot content (from an unpublished CMS
            // editing session) doesn't leak into the migration; other readers in
            // this codebase apply the same guard.
            $rows = $this->db->select("
                SELECT
                    COALESCE(cst.config, '{}') AS config,
                    cse.position AS section_position,
                    cb.position AS block_position,
                    cs.slot AS slot_name
                FROM cms_slot cs
                INNER JOIN cms_block cb ON cb.id = cs.cms_block_id
                INNER JOIN cms_section cse ON cse.id = cb.cms_section_id
                LEFT JOIN cms_slot_translation cst
                    ON cst.cms_slot_id = cs.id
                    AND cst.cms_slot_version_id = cs.version_id
                    AND cst.language_id = ?
                WHERE cse.cms_page_id = UNHEX(?)
                  AND cs.version_id = ?
                  AND cs.type IN ('text', 'html')
                ORDER BY cse.position ASC, cb.position ASC, cs.slot ASC
            ", [$this->db->languageIdBin(), $cmsPageId, $this->db->liveVersionIdBin()]);
        } catch (\Throwable $e) {
            return '';
        }

        $lastValue = '';
        foreach ($rows as $row) {
            $config = json_decode($row->config ?? '{}', true);
            if (! is_array($config)) {
                continue;
            }

            $value = $config['content']['value'] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $lastValue = $value;
            }
        }

        return $lastValue;
    }
}

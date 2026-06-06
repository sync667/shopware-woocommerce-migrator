<?php

namespace App\Shopware\Transformers;

class CategoryTransformer
{
    public function transform(object $category, ?int $wooParentId = null, string $seoTextBelow = ''): array
    {
        $description = $category->description ?? '';

        if ($seoTextBelow !== '') {
            if ($description === '') {
                $description = "<!-- shopware-seo-text -->\n".$seoTextBelow;
            } else {
                $description = $description."\n\n<!-- shopware-seo-text -->\n".$seoTextBelow;
            }
        }

        $data = [
            'name' => $category->name ?: 'Unnamed Category',
            'description' => $description,
            'menu_order' => (int) ($category->sort_order ?? 0),
            'meta_data' => [],
        ];

        if ($wooParentId) {
            $data['parent'] = $wooParentId;
        }

        if ($category->id ?? '') {
            $data['meta_data'][] = ['key' => '_shopware_category_id', 'value' => $category->id];
        }

        // Yoast SEO term meta. Keys are inert when Yoast isn't installed, so it's
        // safe to emit unconditionally — mirrors ProductTransformer's pattern.
        if ($category->meta_title ?? '') {
            $data['meta_data'][] = ['key' => '_yoast_wpseo_title', 'value' => $category->meta_title];
        }

        if ($category->meta_description ?? '') {
            $data['meta_data'][] = ['key' => '_yoast_wpseo_metadesc', 'value' => $category->meta_description];
        }

        $keywords = trim((string) ($category->keywords ?? ''));
        if ($keywords !== '') {
            $list = array_values(array_filter(array_map('trim', explode(',', $keywords))));
            if ($list !== []) {
                $data['meta_data'][] = ['key' => '_yoast_wpseo_focuskw', 'value' => $list[0]];
                $data['meta_data'][] = ['key' => 'rank_math_focus_keyword', 'value' => $list[0]];
                $data['meta_data'][] = ['key' => '_shopware_category_keywords', 'value' => implode(', ', $list)];
            }
        }

        return $data;
    }
}

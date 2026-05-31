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

        return $data;
    }
}

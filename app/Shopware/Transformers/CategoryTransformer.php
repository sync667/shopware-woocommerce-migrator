<?php

namespace App\Shopware\Transformers;

class CategoryTransformer
{
    public function transform(object $category, ?int $wooParentId = null): array
    {
        $data = [
            'name' => $category->name ?: 'Unnamed Category',
            'description' => $category->description ?? '',
            'menu_order' => (int) ($category->sort_order ?? 0),
            'meta_data' => [],
        ];

        if ($wooParentId) {
            $data['parent'] = $wooParentId;
        }

        // Store Shopware category ID for reference
        if ($category->id ?? '') {
            $data['meta_data'][] = ['key' => '_shopware_category_id', 'value' => $category->id];
        }

        return $data;
    }
}

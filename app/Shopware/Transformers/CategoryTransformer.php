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
        ];

        if ($wooParentId) {
            $data['parent'] = $wooParentId;
        }

        return $data;
    }
}

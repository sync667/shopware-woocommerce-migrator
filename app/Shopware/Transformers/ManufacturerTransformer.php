<?php

namespace App\Shopware\Transformers;

class ManufacturerTransformer
{
    public function transform(object $manufacturer): array
    {
        return [
            'shopware_id' => $manufacturer->id,
            'name' => $manufacturer->name ?: 'Unknown',
        ];
    }
}

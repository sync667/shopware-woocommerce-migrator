<?php

namespace App\Shopware\Transformers;

class TaxTransformer
{
    public function transform(object $tax): array
    {
        return [
            'shopware_id' => $tax->id,
            'name' => $tax->name,
            'slug' => $this->slugify($tax->name),
            'rate' => (float) $tax->tax_rate,
        ];
    }

    public function transformRule(object $rule, string $taxClassName): array
    {
        return [
            'country' => $rule->country_iso,
            'state' => '',
            'rate' => (string) $rule->rate,
            'name' => $taxClassName,
            'priority' => 1,
            'compound' => false,
            'shipping' => true,
            'class' => $taxClassName,
        ];
    }

    protected function slugify(string $name): string
    {
        return trim(strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', trim($name))), '-');
    }
}

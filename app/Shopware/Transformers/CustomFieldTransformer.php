<?php

namespace App\Shopware\Transformers;

class CustomFieldTransformer
{
    public function transformFieldsToMetaData(?string $customFieldsJson): array
    {
        if (! $customFieldsJson) {
            return [];
        }

        $customFields = json_decode($customFieldsJson, true);

        if (! is_array($customFields) || empty($customFields)) {
            return [];
        }

        $metaData = [];

        foreach ($customFields as $key => $value) {
            $metaData[] = [
                'key' => '_custom_'.$key,
                'value' => $this->normalizeValue($value),
            ];
        }

        return $metaData;
    }

    protected function normalizeValue(mixed $value): mixed
    {
        // Handle arrays and objects
        if (is_array($value) || is_object($value)) {
            return json_encode($value);
        }

        // Handle booleans
        if (is_bool($value)) {
            return $value;
        }

        // Handle null
        if ($value === null) {
            return '';
        }

        // Return as-is
        return $value;
    }

    public function transformFieldDefinition(object $field): array
    {
        return [
            'name' => $field->name ?? '',
            'type' => $this->mapFieldType($field->type ?? 'text'),
            'config' => $field->config ?? null,
            'meta_data' => [
                ['key' => '_shopware_id', 'value' => $field->id ?? ''],
                ['key' => '_shopware_set_id', 'value' => $field->set_id ?? ''],
                ['key' => '_shopware_type', 'value' => $field->type ?? ''],
            ],
        ];
    }

    protected function mapFieldType(string $shopwareType): string
    {
        return match ($shopwareType) {
            'text', 'html' => 'text',
            'int' => 'number',
            'float' => 'number',
            'bool' => 'checkbox',
            'datetime' => 'date',
            'select' => 'select',
            'json' => 'textarea',
            default => 'text',
        };
    }
}

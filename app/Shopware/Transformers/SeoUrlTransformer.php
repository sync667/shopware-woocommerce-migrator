<?php

namespace App\Shopware\Transformers;

class SeoUrlTransformer
{
    public function transform(object $seoUrl, ?string $newUrl = null): array
    {
        return [
            'old_url' => '/'.ltrim($seoUrl->seo_path_info ?? '', '/'),
            'new_url' => $newUrl,
            'status_code' => 301,
            'meta_data' => [
                ['key' => '_shopware_id', 'value' => $seoUrl->id ?? ''],
                ['key' => '_shopware_foreign_key', 'value' => $seoUrl->foreign_key ?? ''],
                ['key' => '_shopware_route_name', 'value' => $seoUrl->route_name ?? ''],
                ['key' => '_is_canonical', 'value' => (bool) ($seoUrl->is_canonical ?? false)],
            ],
        ];
    }

    public function generateWordPressRedirectRule(object $seoUrl, ?string $newSlug = null): string
    {
        $oldPath = ltrim($seoUrl->seo_path_info ?? '', '/');
        $newPath = $newSlug ? ltrim($newSlug, '/') : '';

        // Generate Apache .htaccess redirect rule
        return "Redirect 301 /{$oldPath} /{$newPath}";
    }

    public function generateNginxRedirectRule(object $seoUrl, ?string $newSlug = null): string
    {
        $oldPath = ltrim($seoUrl->seo_path_info ?? '', '/');
        $newPath = $newSlug ? ltrim($newSlug, '/') : '';

        // Generate Nginx redirect rule
        return "rewrite ^/{$oldPath}$ /{$newPath} permanent;";
    }
}

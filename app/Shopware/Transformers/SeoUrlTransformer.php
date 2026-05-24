<?php

namespace App\Shopware\Transformers;

use InvalidArgumentException;

class SeoUrlTransformer
{
    private const TARGETS = [
        'product' => ['slug' => '/product/%s/', 'fallback' => '/?p=%d'],
        'category' => ['slug' => '/product-category/%s/', 'fallback' => '/?cat=%d'],
        'cms_page' => ['slug' => '/%s/', 'fallback' => '/?page_id=%d'],
    ];

    /**
     * @return array{
     *   source: string,
     *   target: string,
     *   code: int,
     *   is_self_redirect: bool,
     *   metadata: array{shopware_id: string, foreign_key: string, route_name: string, is_canonical: bool}
     * }
     */
    public function transform(object $seoUrl, string $entityType, ?int $wooId, ?string $wooSlug): array
    {
        if (! isset(self::TARGETS[$entityType])) {
            throw new InvalidArgumentException("Unknown entity type: {$entityType}");
        }

        $source = $this->normalizeSource($seoUrl->seo_path_info ?? '');
        $target = $this->buildTarget($entityType, $wooId, $wooSlug);

        return [
            'source' => $source,
            'target' => $target,
            'code' => 301,
            'is_self_redirect' => $source === $target || $source.'/' === $target,
            'metadata' => [
                'shopware_id' => (string) ($seoUrl->id ?? ''),
                'foreign_key' => (string) ($seoUrl->foreign_key ?? ''),
                'route_name' => (string) ($seoUrl->route_name ?? ''),
                'is_canonical' => (bool) ($seoUrl->is_canonical ?? false),
            ],
        ];
    }

    private function normalizeSource(string $path): string
    {
        $path = strtok($path, '?');
        if ($path === false) {
            $path = '';
        }

        $path = preg_replace('#/+#', '/', $path) ?? '';
        $path = '/'.ltrim($path, '/');
        $path = rtrim($path, '/');

        return $path === '' ? '/' : $path;
    }

    private function buildTarget(string $entityType, ?int $wooId, ?string $wooSlug): string
    {
        $templates = self::TARGETS[$entityType];

        if ($wooSlug !== null && $wooSlug !== '') {
            return sprintf($templates['slug'], $wooSlug);
        }

        if ($wooId !== null) {
            return sprintf($templates['fallback'], $wooId);
        }

        return '';
    }
}

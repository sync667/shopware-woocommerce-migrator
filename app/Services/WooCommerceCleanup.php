<?php

namespace App\Services;

use App\Models\MigrationLog;
use App\Models\MigrationRun;
use App\Shopware\Readers\CmsPageReader;

class WooCommerceCleanup
{
    public function __construct(
        protected WooCommerceClient $woocommerce,
        protected ?int $migrationId = null,
        protected ?WordPressMediaClient $wordpress = null,
        protected ?MigrationRun $migration = null
    ) {}

    /** @return string[] */
    public static function entities(): array
    {
        return [
            'orders',
            'reviews',
            'coupons',
            'products',
            'product_attributes',
            'product_tags',
            'categories',
            'customers',
            'tax_rates',
            'tax_classes',
            'shipping_zones',
            'pages',
            'media',
        ];
    }

    public function cleanEntity(string $entity): array
    {
        return match ($entity) {
            'orders' => $this->deleteAllOrders(),
            'reviews' => $this->deleteAllReviews(),
            'coupons' => $this->deleteAllCoupons(),
            'products' => $this->deleteAllProducts(),
            'product_attributes' => $this->deleteAllProductAttributes(),
            'product_tags' => $this->deleteAllProductTags(),
            'categories' => $this->deleteAllCategories(),
            'customers' => $this->deleteAllCustomers(),
            'tax_rates' => $this->deleteAllTaxRates(),
            'tax_classes' => $this->deleteAllTaxClasses(),
            'shipping_zones' => $this->deleteAllShippingZones(),
            'pages' => $this->deleteAllPages(),
            'media' => $this->deleteAllMedia(),
            default => throw new \InvalidArgumentException("Unknown cleanup entity: {$entity}"),
        };
    }

    protected function deleteAllOrders(): array
    {
        return $this->batchDeleteAll('orders', 'orders');
    }

    protected function deleteAllCoupons(): array
    {
        return $this->batchDeleteAll('coupons', 'coupons');
    }

    protected function deleteAllProducts(): array
    {
        return $this->batchDeleteAll('products', 'products');
    }

    protected function deleteAllProductAttributes(): array
    {
        return $this->batchDeleteAll('products/attributes', 'product attributes');
    }

    protected function deleteAllProductTags(): array
    {
        return $this->batchDeleteAll('products/tags', 'product tags');
    }

    protected function deleteAllCategories(): array
    {
        return $this->batchDeleteAll(
            'products/categories',
            'categories',
            fn ($c) => $c['slug'] !== 'uncategorized'
        );
    }

    protected function deleteAllCustomers(): array
    {
        return $this->batchDeleteAll('customers', 'customers', null, ['reassign' => '0']);
    }

    /**
     * Delete all reviews
     */
    protected function deleteAllReviews(): array
    {
        $deleted = 0;
        $failed = 0;

        try {
            do {
                $reviews = $this->woocommerce->get('products/reviews', [
                    'per_page' => 100,
                    'page' => 1,
                ]);

                $deletedThisRound = 0;
                foreach ($reviews as $review) {
                    try {
                        $this->woocommerce->delete("products/reviews/{$review['id']}", ['force' => true]);
                        $deleted++;
                        $deletedThisRound++;
                    } catch (\GuzzleHttp\Exception\ClientException $e) {
                        // WC returns 404 for reviews whose product was already deleted (orphaned comments).
                        // Fall back to the WordPress comments API which has no product validation.
                        if ($e->getResponse()->getStatusCode() === 404 && $this->wordpress) {
                            try {
                                $this->wordpress->deleteComment($review['id']);
                                $deleted++;
                                $deletedThisRound++;
                            } catch (\Exception $wpE) {
                                $failed++;
                                $this->log('warning', "Failed to delete orphaned review {$review['id']} via WP comments: {$wpE->getMessage()}", null, 'cleanup');
                            }
                        } else {
                            $failed++;
                            $this->log('warning', "Failed to delete review {$review['id']}: {$e->getMessage()}", null, 'cleanup');
                        }
                    } catch (\Exception $e) {
                        $failed++;
                        $this->log('warning', "Failed to delete review {$review['id']}: {$e->getMessage()}", null, 'cleanup');
                    }
                }
            } while (! empty($reviews) && $deletedThisRound > 0);

            $this->log('info', "Deleted {$deleted} reviews", null, 'cleanup');
        } catch (\Exception $e) {
            $this->log('error', "Review cleanup failed: {$e->getMessage()}", null, 'cleanup');
        }

        return ['deleted' => $deleted, 'failed' => $failed];
    }

    protected function deleteAllTaxRates(): array
    {
        return $this->batchDeleteAll('taxes', 'tax rates');
    }

    /**
     * Fetch one page at a time, batch-delete all IDs (optionally filtered), repeat until exhausted.
     * Reduces API calls from O(n) individual DELETEs to O(n/100) batch POSTs.
     *
     * @param  callable|null  $filter  Optional item-level filter (e.g. skip 'uncategorized')
     * @param  string[]  $extraQuery  Extra query params forwarded to the batch endpoint (e.g. ['reassign' => '0'])
     */
    protected function batchDeleteAll(
        string $endpoint,
        string $logName,
        ?callable $filter = null,
        array $extraQuery = []
    ): array {
        $deleted = 0;
        $failed = 0;

        try {
            do {
                $items = $this->woocommerce->get($endpoint, ['per_page' => 100, 'page' => 1]);
                $toDelete = $filter ? array_values(array_filter($items, $filter)) : $items;
                $ids = array_column($toDelete, 'id');

                if (empty($ids)) {
                    break;
                }

                try {
                    $this->woocommerce->batchDelete($endpoint, $ids, $extraQuery);
                    $deleted += count($ids);
                } catch (\Exception $e) {
                    $failed += count($ids);
                    $this->log('warning', "Batch delete failed for {$logName}: {$e->getMessage()}", null, 'cleanup');
                    break;
                }
            } while (count($items) === 100);

            $this->log('info', "Deleted {$deleted} {$logName}", null, 'cleanup');
        } catch (\Exception $e) {
            $this->log('error', "{$logName} cleanup failed: {$e->getMessage()}", null, 'cleanup');
        }

        return ['deleted' => $deleted, 'failed' => $failed];
    }

    /**
     * Delete all custom tax classes (standard class cannot be deleted)
     */
    protected function deleteAllTaxClasses(): array
    {
        $deleted = 0;
        $failed = 0;

        try {
            $classes = $this->woocommerce->get('taxes/classes');

            foreach ($classes as $class) {
                // WooCommerce built-in classes cannot be deleted
                if (in_array($class['slug'], ['standard', 'reduced-rate', 'zero-rate'])) {
                    continue;
                }

                try {
                    $this->woocommerce->delete("taxes/classes/{$class['slug']}", ['force' => true]);
                    $deleted++;
                } catch (\Exception $e) {
                    $failed++;
                    $this->log('warning', "Failed to delete tax class {$class['slug']}: {$e->getMessage()}", null, 'cleanup');
                }
            }

            $this->log('info', "Deleted {$deleted} tax classes", null, 'cleanup');
        } catch (\Exception $e) {
            $this->log('error', "Tax class cleanup failed: {$e->getMessage()}", null, 'cleanup');
        }

        return ['deleted' => $deleted, 'failed' => $failed];
    }

    /**
     * Delete all shipping zones (zone 0 "Rest of the World" cannot be deleted)
     */
    protected function deleteAllShippingZones(): array
    {
        $deleted = 0;
        $failed = 0;

        try {
            $zones = $this->woocommerce->get('shipping/zones');

            foreach ($zones as $zone) {
                // Zone 0 is the built-in "Rest of the World" zone — cannot be deleted
                if ($zone['id'] === 0) {
                    continue;
                }

                try {
                    $this->woocommerce->delete("shipping/zones/{$zone['id']}", ['force' => true]);
                    $deleted++;
                } catch (\Exception $e) {
                    $failed++;
                    $this->log('warning', "Failed to delete shipping zone {$zone['id']}: {$e->getMessage()}", null, 'cleanup');
                }
            }

            $this->log('info', "Deleted {$deleted} shipping zones", null, 'cleanup');
        } catch (\Exception $e) {
            $this->log('error', "Shipping zone cleanup failed: {$e->getMessage()}", null, 'cleanup');
        }

        return ['deleted' => $deleted, 'failed' => $failed];
    }

    /**
     * Delete only WordPress pages that will be re-migrated from Shopware.
     * Pages not created by a previous migration run are left untouched.
     */
    protected function deleteAllPages(): array
    {
        if (! $this->wordpress || ! $this->migration) {
            return ['deleted' => 0, 'failed' => 0, 'skipped' => true];
        }

        $deleted = 0;
        $failed = 0;

        try {
            $targetSlugs = $this->resolveShopwarePageSlugs();

            if (empty($targetSlugs)) {
                return ['deleted' => 0, 'failed' => 0, 'skipped' => true];
            }

            // Collect all matching page IDs first (without modifying the list),
            // then delete them — avoids pagination shifting issues.
            $toDelete = [];
            $page = 1;
            do {
                $wpPages = $this->wordpress->getPages($page);
                foreach ($wpPages as $wpPage) {
                    if (in_array($wpPage['slug'], $targetSlugs, true)) {
                        $toDelete[] = $wpPage['id'];
                    }
                }
                $page++;
            } while (count($wpPages) === 100);

            foreach ($toDelete as $pageId) {
                try {
                    $this->wordpress->deletePage($pageId);
                    $deleted++;
                } catch (\Exception $e) {
                    $failed++;
                    $this->log('warning', "Failed to delete page {$pageId}: {$e->getMessage()}", null, 'cleanup');
                }
            }

            $this->log('info', "Deleted {$deleted} pages (matched against Shopware pages to be migrated)", null, 'cleanup');
        } catch (\Exception $e) {
            $this->log('error', "Page cleanup failed: {$e->getMessage()}", null, 'cleanup');
        }

        return ['deleted' => $deleted, 'failed' => $failed];
    }

    /**
     * Resolve the set of WordPress slugs that the Shopware CMS pages would produce.
     *
     * @return string[]
     */
    protected function resolveShopwarePageSlugs(): array
    {
        try {
            $db = ShopwareDB::fromMigration($this->migration);
            $reader = new CmsPageReader($db);

            $cmsOptions = $this->migration->settings['cms_options'] ?? [];
            $selectedIds = $cmsOptions['selected_ids'] ?? null;

            $pages = $selectedIds
                ? $reader->fetchByIds($selectedIds)
                : (($cmsOptions['migrate_all'] ?? false) ? $reader->fetchAll() : []);

            $db->disconnect();

            return array_values(array_filter(array_map(
                fn ($p) => $this->slugify($p->name ?? ''),
                $pages
            )));
        } catch (\Exception $e) {
            $this->log('warning', "Could not resolve Shopware page slugs for cleanup: {$e->getMessage()}", null, 'cleanup');

            return [];
        }
    }

    /**
     * Replicate WordPress sanitize_title() slug generation used by CmsPageTransformer.
     */
    protected function slugify(string $name): string
    {
        $name = strtolower($name);
        $name = preg_replace('/[^a-z0-9-]+/', '-', $name);

        return trim($name, '-');
    }

    /**
     * Permanently delete all media attachments from the WordPress media library.
     * Products/categories are deleted first (via other entity cleanups), so their
     * attached images become orphans — this removes them.
     */
    protected function deleteAllMedia(): array
    {
        if (! $this->wordpress) {
            return ['deleted' => 0, 'failed' => 0, 'skipped' => true];
        }

        $deleted = 0;
        $failed = 0;
        $page = 1;

        try {
            do {
                $items = $this->wordpress->listMedia($page);

                if (empty($items)) {
                    break;
                }

                foreach ($items as $item) {
                    try {
                        $this->wordpress->deleteMedia($item['id']);
                        $deleted++;
                    } catch (\Exception $e) {
                        $failed++;
                        $this->log('warning', "Failed to delete media {$item['id']}: {$e->getMessage()}", null, 'cleanup');
                    }
                }

                // If we got a full page, there may be more — but since we always
                // fetch page 1 after each deletion round (items shift), we only
                // break when an empty page is returned.
                $page = 1;
            } while (count($items) === 100);

            $this->log('info', "Deleted {$deleted} media attachments", null, 'cleanup');
        } catch (\Exception $e) {
            $this->log('error', "Media cleanup failed: {$e->getMessage()}", null, 'cleanup');
        }

        return ['deleted' => $deleted, 'failed' => $failed];
    }

    /**
     * Log cleanup activity
     */
    protected function log(string $level, string $message, ?string $shopwareId = null, ?string $entityType = null): void
    {
        if (! $this->migrationId) {
            return;
        }

        MigrationLog::create([
            'migration_id' => $this->migrationId,
            'entity_type' => $entityType,
            'shopware_id' => $shopwareId,
            'level' => $level,
            'message' => $message,
            'created_at' => now(),
        ]);
    }
}

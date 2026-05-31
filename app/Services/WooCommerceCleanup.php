<?php

namespace App\Services;

use App\Models\MigrationEntity;
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

    /**
     * Full list of cleanup entity types, in safe FK-deletion order.
     * Used as a static reference for tooling — prefer entitiesFor($migration) when
     * dispatching a real run because that variant honors per-migration safety flags.
     *
     * @return string[]
     */
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

    /**
     * Returns the cleanup steps that should actually run for a given migration.
     * Omits 'pages' when CMS migration is disabled (the operator can't possibly want
     * the migrator to delete WP pages that won't be replaced) and omits 'media'
     * unless the operator explicitly opted in via cleanup_options.delete_media.
     *
     * @return string[]
     */
    public static function entitiesFor(MigrationRun $migration): array
    {
        $cmsOptions = $migration->settings['cms_options'] ?? [];
        $cmsEnabled = ! empty($cmsOptions['migrate_all'])
            || (is_array($cmsOptions['selected_ids'] ?? null) && $cmsOptions['selected_ids'] !== []);

        $cleanupOptions = $migration->settings['cleanup_options'] ?? [];
        $deleteMedia = (bool) ($cleanupOptions['delete_media'] ?? false);

        $entities = static::entities();

        if (! $cmsEnabled) {
            $entities = array_values(array_filter($entities, fn ($e) => $e !== 'pages'));
        }
        if (! $deleteMedia) {
            $entities = array_values(array_filter($entities, fn ($e) => $e !== 'media'));
        }

        return $entities;
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

                $this->log('info', "Cleaning reviews: {$deleted} deleted so far…", null, 'cleanup');
            } while (! empty($reviews) && $deletedThisRound > 0);

            $this->log('info', "Finished cleaning reviews: {$deleted} deleted", null, 'cleanup');
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
                    $this->log('info', "Cleaning {$logName}: {$deleted} deleted so far…", null, 'cleanup');
                } catch (\Exception $e) {
                    $failed += count($ids);
                    $this->log('warning', "Batch delete failed for {$logName}: {$e->getMessage()}", null, 'cleanup');
                    break;
                }
            } while (count($items) === 100);

            $this->log('info', "Finished cleaning {$logName}: {$deleted} deleted", null, 'cleanup');
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

        $cmsOptions = $this->migration->settings['cms_options'] ?? [];
        $cmsEnabled = ! empty($cmsOptions['migrate_all'])
            || (is_array($cmsOptions['selected_ids'] ?? null) && $cmsOptions['selected_ids'] !== []);

        if (! $cmsEnabled) {
            // Belt-and-suspenders guard — entitiesFor() should have already filtered
            // 'pages' out of the dispatched job list when CMS migration is off.
            $this->log('info', 'Skipping page cleanup: CMS migration is not enabled, so no Shopware pages target this WP — leaving all pages untouched.', null, 'cleanup');

            return ['deleted' => 0, 'failed' => 0, 'skipped' => true];
        }

        $deleted = 0;
        $failed = 0;

        try {
            $targetSlugs = $this->resolveShopwarePageSlugs();

            if (empty($targetSlugs)) {
                $this->log('info', 'Skipping page cleanup: Shopware CMS produced an empty target slug list (no pages would collide).', null, 'cleanup');

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
     * Delete WordPress media attachments according to cleanup_options.media_mode:
     *
     *  - 'migrated_only' (default): only delete attachments the migrator has tracked
     *    in MigrationEntity across ALL past runs. Operator-uploaded blog images,
     *    hand-curated page hero shots, theme demo content, etc. stay put.
     *  - 'all': nuke everything (the original behavior). Operator must explicitly
     *    opt in via UI — there is no way to set this accidentally.
     */
    protected function deleteAllMedia(): array
    {
        if (! $this->wordpress) {
            return ['deleted' => 0, 'failed' => 0, 'skipped' => true];
        }

        $mode = (string) ($this->migration?->settings['cleanup_options']['media_mode'] ?? 'migrated_only');

        if ($mode === 'migrated_only') {
            return $this->deleteMigratedMedia();
        }

        return $this->deleteAllMediaUnsafe();
    }

    /**
     * Safe mode: collect every WP attachment ID the migrator has produced (in this run
     * or any past run, across all migrations of this tool) and delete only those.
     * Catches media referenced via:
     *  - StateManager mapping rows (entity_type='media')
     *  - Category/manufacturer mappings whose payload carries the WP image id
     *  - Product/variation entities whose payload preserved the image list (dry runs)
     */
    protected function deleteMigratedMedia(): array
    {
        $ids = $this->collectMigratorOwnedMediaIds();

        if ($ids === []) {
            $this->log('info', 'Media cleanup (migrated_only): no previously-migrated attachments tracked — nothing to delete.', null, 'cleanup');

            return ['deleted' => 0, 'failed' => 0, 'skipped' => true];
        }

        $this->log('info', 'Media cleanup (migrated_only): targeting '.count($ids).' attachments tracked from past migrations…', null, 'cleanup');

        $deleted = 0;
        $failed = 0;

        try {
            foreach (array_chunk($ids, 100) as $chunk) {
                $result = $this->wordpress->batchDeleteMedia($chunk);
                $deleted += $result['deleted'];
                $failed += $result['failed'];

                if ($result['deleted'] === 0 && $result['failed'] === count($chunk)) {
                    $this->log('warning', "Media cleanup made no progress on a chunk of {$failed} ids, stopping.", null, 'cleanup');
                    break;
                }

                $this->log('info', "Cleaning media (migrated_only): {$deleted} deleted so far, {$failed} failed", null, 'cleanup');
            }

            $this->log('info', "Finished cleaning media (migrated_only): {$deleted} deleted, {$failed} failed", null, 'cleanup');
        } catch (\Exception $e) {
            $this->log('error', "Media cleanup failed: {$e->getMessage()}", null, 'cleanup');
        }

        return ['deleted' => $deleted, 'failed' => $failed];
    }

    /**
     * UNSAFE mode (cleanup_options.media_mode='all'): wipe everything in the WP media
     * library. Preserved for operators who explicitly want a true clean-slate.
     */
    protected function deleteAllMediaUnsafe(): array
    {
        $deleted = 0;
        $failed = 0;

        $this->log('warning', "Media cleanup mode is 'all' — deleting EVERY attachment in the WP media library, including any uploaded outside the migrator.", null, 'cleanup');

        try {
            do {
                // Always fetch page 1 — after each deletion round the list shifts up.
                $items = $this->wordpress->listMedia(1, 100);

                if (empty($items)) {
                    break;
                }

                $ids = array_column($items, 'id');
                $result = $this->wordpress->batchDeleteMedia($ids);
                $deleted += $result['deleted'];
                $failed += $result['failed'];

                if ($result['deleted'] === 0) {
                    // No progress — batch API failed and fallback also failed; stop to avoid infinite loop.
                    $this->log('warning', "Media cleanup made no progress this round ({$result['failed']} failed), stopping.", null, 'cleanup');
                    break;
                }

                $this->log('info', "Cleaning media: {$deleted} attachments deleted so far…", null, 'cleanup');
            } while (count($items) === 100);

            $this->log('info', "Finished cleaning media: {$deleted} attachments deleted", null, 'cleanup');
        } catch (\Exception $e) {
            $this->log('error', "Media cleanup failed: {$e->getMessage()}", null, 'cleanup');
        }

        return ['deleted' => $deleted, 'failed' => $failed];
    }

    /**
     * Returns the set of WP attachment ids the migrator has ever produced.
     *
     * @return int[]
     */
    protected function collectMigratorOwnedMediaIds(): array
    {
        $ids = [];

        // Direct media mappings — every successful image upload writes one.
        $mediaMap = MigrationEntity::where('entity_type', 'media')
            ->whereNotNull('woo_id')
            ->pluck('woo_id')
            ->all();
        foreach ($mediaMap as $id) {
            $ids[(int) $id] = true;
        }

        // Media referenced from category payloads (image id stored after upload).
        $categoryRows = MigrationEntity::where('entity_type', 'category')
            ->whereNotNull('payload')
            ->get(['payload']);
        foreach ($categoryRows as $row) {
            $imgId = $row->payload['image']['id'] ?? null;
            if (is_numeric($imgId) && (int) $imgId > 0) {
                $ids[(int) $imgId] = true;
            }
        }

        // Media referenced from manufacturer/product payloads (gallery + cover).
        $productRows = MigrationEntity::whereIn('entity_type', ['product', 'variation', 'manufacturer'])
            ->whereNotNull('payload')
            ->get(['payload']);
        foreach ($productRows as $row) {
            $payload = $row->payload ?? [];
            foreach ($payload['images'] ?? [] as $img) {
                if (is_array($img) && isset($img['id']) && is_numeric($img['id'])) {
                    $ids[(int) $img['id']] = true;
                }
            }
            if (isset($payload['image']['id']) && is_numeric($payload['image']['id'])) {
                $ids[(int) $payload['image']['id']] = true;
            }
        }

        return array_keys($ids);
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

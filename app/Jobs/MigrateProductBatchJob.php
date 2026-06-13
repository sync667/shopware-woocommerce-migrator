<?php

namespace App\Jobs;

use App\Models\MigrationLog;
use App\Models\MigrationRun;
use App\Services\ContentMigrator;
use App\Services\ImageMigrator;
use App\Services\ShopwareDB;
use App\Services\StateManager;
use App\Services\WooCommerceClient;
use App\Shopware\Readers\ProductReader;
use App\Shopware\Transformers\ProductTransformer;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class MigrateProductBatchJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 5;

    public int $timeout = 1800; // 30 minutes per batch

    public function __construct(
        protected int $migrationId,
        protected array $productIds
    ) {}

    public function handle(StateManager $stateManager): void
    {
        $migration = MigrationRun::findOrFail($this->migrationId);

        if (app(\App\Services\CancellationService::class)->isCancelled($this->migrationId)) {
            $this->batch()?->cancel();

            return;
        }

        $db = ShopwareDB::fromMigration($migration);
        $woo = WooCommerceClient::fromMigration($migration);
        $imageMigrator = ImageMigrator::fromMigration($migration);
        $contentMigrator = new ContentMigrator($imageMigrator);
        $reader = new ProductReader($db);
        $transformer = new ProductTransformer($contentMigrator);

        try {
            foreach ($this->productIds as $productId) {
                if (app(\App\Services\CancellationService::class)->isCancelled($this->migrationId)) {
                    $this->batch()?->cancel();

                    return;
                }

                if ($stateManager->alreadyMigrated('product', $productId, $this->migrationId)) {
                    continue;
                }

                try {
                    $this->migrateProduct(
                        $productId, $migration, $db, $woo, $imageMigrator, $reader, $transformer, $stateManager
                    );
                } catch (\Throwable $e) {
                    $stateManager->markFailed('product', $productId, $this->migrationId, $e->getMessage());
                    $this->log('error', "Failed to migrate product: {$e->getMessage()}", $productId);
                }
            }
        } finally {
            $db->disconnect();
        }
    }

    protected function migrateProduct(
        string $productId,
        MigrationRun $migration,
        ShopwareDB $db,
        WooCommerceClient $woo,
        ImageMigrator $imageMigrator,
        ProductReader $reader,
        ProductTransformer $transformer,
        StateManager $stateManager,
    ): void {
        $product = $reader->fetchOne($productId);

        if ($product === null) {
            $stateManager->markFailed('product', $productId, $this->migrationId, 'Product not found in Shopware');
            $this->log('warning', 'Product not found in Shopware', $productId);

            return;
        }

        try {
            $categoryWooIds = [];
            foreach ($reader->fetchCategories($product->id) as $cat) {
                $wooId = $stateManager->get('category', $cat->category_id, $this->migrationId);
                if ($wooId) {
                    $categoryWooIds[] = $wooId;
                }
            }

            $manufacturerWooId = null;
            $manufacturerAttribute = null;
            if (! empty($product->manufacturer_id)) {
                $manufacturerWooId = $stateManager->get('manufacturer', $product->manufacturer_id, $this->migrationId);

                $wooManufacturerAttributeId = $stateManager->get('manufacturer_attribute', 'global', $this->migrationId);
                if ($wooManufacturerAttributeId) {
                    $manufacturerEntity = $stateManager->getEntity('manufacturer', $product->manufacturer_id, $this->migrationId);
                    $manufacturerName = $manufacturerEntity?->payload['name'] ?? null;
                    if ($manufacturerName) {
                        $manufacturerAttribute = [
                            'id' => $wooManufacturerAttributeId,
                            'name' => 'Manufacturer',
                            'options' => [$manufacturerName],
                            'visible' => true,
                            'variation' => false,
                        ];
                    }
                }
            }

            $taxClassSlug = '';
            if (! empty($product->tax_id)) {
                $taxMap = $stateManager->getTaxClassMap($this->migrationId);
                if (isset($taxMap[$product->tax_id])) {
                    $taxClassSlug = $taxMap[$product->tax_id];
                }
            }

            $configuratorSettings = $reader->fetchConfiguratorSettings($product->id);
            $properties = $reader->fetchProperties($product->id);
            $tags = array_map(fn ($t) => $t->name, $reader->fetchTags($product->id));

            $attributes = array_merge(
                $transformer->buildAttributes($configuratorSettings, true),
                $transformer->buildAttributes($properties, false),
            );

            if ($manufacturerAttribute !== null) {
                $attributes[] = $manufacturerAttribute;
            }

            if (! empty($product->delivery_time_name)) {
                $attributes[] = [
                    'name' => 'Delivery time',
                    'options' => [(string) $product->delivery_time_name],
                    'visible' => true,
                    'variation' => false,
                    'position' => count($attributes),
                ];
            }

            $primaryCategoryWooId = null;
            $mainCategoryShopwareId = $reader->fetchMainCategoryId($product->id);
            if ($mainCategoryShopwareId !== null) {
                $primaryCategoryWooId = $stateManager->get('category', $mainCategoryShopwareId, $this->migrationId);
            }

            $omnibusLowestPrice = null;
            if ($migration->settings['omnibus_options']['enabled'] ?? false) {
                $omnibusLowestPrice = $reader->fetchOmnibusLowestPrice($product->id);
            }

            $blockPurchaseRule = (bool) ($migration->settings['remizasklep_options']['block_purchase_on_closeout'] ?? false);

            $data = $transformer->transform(
                $product,
                $categoryWooIds,
                $manufacturerWooId,
                $taxClassSlug,
                $attributes,
                $tags,
                $primaryCategoryWooId,
                $omnibusLowestPrice,
                $blockPurchaseRule,
            );

            $variants = $reader->fetchVariants($product->id);
            if (! empty($variants)) {
                $data['type'] = 'variable';

                // Shopware main_variant_id → WC default_attributes preselects the dropdown.
                $mainVariantId = $product->main_variant_id ?? null;
                if ($mainVariantId !== null && $mainVariantId !== '') {
                    try {
                        $defaultOptions = $reader->fetchVariantOptions($mainVariantId);
                        if (! empty($defaultOptions)) {
                            $data['default_attributes'] = $transformer->buildVariantOptionAttributes($defaultOptions);
                        }
                    } catch (\Throwable $e) {
                        $this->log('warning', "Could not resolve main_variant_id options ({$mainVariantId}): {$e->getMessage()}", $product->id);
                    }
                }
            }

            if ($migration->is_dry_run) {
                $stateManager->markSkipped('product', $product->id, $this->migrationId, $data);
                $this->log('info', "Dry run: product '{$data['name']}'", $product->id);

                foreach ($variants as $variant) {
                    try {
                        $variantOptions = $reader->fetchVariantOptions($variant->id);
                        $optionAttributes = $transformer->buildVariantOptionAttributes($variantOptions);
                        $variantData = $transformer->transformVariant($variant, $optionAttributes, $blockPurchaseRule);
                        // Carry the parent shopware id so downstream consumers (SEO URL
                        // job, order line item resolver) can walk variant → parent.
                        $variantData['parent_shopware_id'] = $product->id;
                        $stateManager->markSkipped('variation', $variant->id, $this->migrationId, $variantData);
                    } catch (\Throwable $e) {
                        $stateManager->markFailed('variation', $variant->id, $this->migrationId, $e->getMessage());
                        $this->log('error', "Dry run variant failed: {$e->getMessage()}", $variant->id, 'variation');
                    }
                }

                return;
            }

            $media = $reader->fetchMedia($product->id);
            $imageIds = [];
            foreach ($media as $m) {
                if (empty($m->file_name) || empty($m->file_extension)) {
                    continue;
                }
                $imageUrl = $imageMigrator->buildShopwareMediaUrl($m->media_id, $m->file_name, $m->file_extension, isset($m->uploaded_at) ? (int) $m->uploaded_at : null);
                $wpImageId = $imageMigrator->migrate($imageUrl, "{$m->file_name}.{$m->file_extension}", $m->title ?? '', $m->alt ?? '', $m->media_id);
                if ($wpImageId) {
                    $imageIds[] = ['id' => $wpImageId, 'media_id' => $m->media_id];
                }
            }

            if (! empty($imageIds)) {
                $coverId = $product->cover_id ?? null;
                $featuredSet = false;
                foreach ($imageIds as $img) {
                    if ($coverId && $img['media_id'] === $coverId && ! $featuredSet) {
                        // Cover goes first; WooCommerce treats index-0 as the featured image.
                        $data['images'] = array_merge([['id' => $img['id']]], $data['images'] ?? []);
                        $featuredSet = true;
                    } else {
                        $data['images'][] = ['id' => $img['id']];
                    }
                }
                // No fallback unshift needed: when no cover match, images are already in
                // order with the first image at index 0 via the else branch above.
            }

            $result = $woo->createOrFind('products', $data, 'sku', $product->sku);
            $wooProductId = $result['id'] ?? null;

            if (! $wooProductId) {
                throw new \RuntimeException('Failed to create product in WooCommerce');
            }

            $stateManager->set('product', $product->id, $wooProductId, $this->migrationId);
            $this->log('info', "Migrated product '{$data['name']}' → WC #{$wooProductId}", $product->id);

            $this->maybeWriteDeliveryTiers($migration, $product, $wooProductId);

            foreach ($variants as $variant) {
                $this->migrateVariant($variant, $wooProductId, $reader, $transformer, $woo, $imageMigrator, $stateManager, $blockPurchaseRule);
            }

            // Cross-sells are linked in a separate job (LinkCrossSellsJob) AFTER the
            // products batch completes — otherwise forward refs to products in other
            // batches get silently dropped (their state mappings don't exist yet).
        } catch (\Throwable $e) {
            $stateManager->markFailed('product', $product->id, $this->migrationId, $e->getMessage());
            $this->log('error', "Failed: {$e->getMessage()}", $product->id);
        }
    }

    protected function migrateVariant(
        object $variant,
        int $wooProductId,
        ProductReader $reader,
        ProductTransformer $transformer,
        WooCommerceClient $woo,
        ImageMigrator $imageMigrator,
        StateManager $stateManager,
        bool $blockPurchaseRule = false,
    ): void {
        if ($stateManager->alreadyMigrated('variation', $variant->id, $this->migrationId)) {
            return;
        }

        try {
            $variantOptions = $reader->fetchVariantOptions($variant->id);
            $optionAttributes = $transformer->buildVariantOptionAttributes($variantOptions);
            $data = $transformer->transformVariant($variant, $optionAttributes, $blockPurchaseRule);

            $media = $reader->fetchMedia($variant->id);
            if (! empty($media)) {
                $m = $media[0];
                if (! empty($m->file_name) && ! empty($m->file_extension)) {
                    $imageUrl = $imageMigrator->buildShopwareMediaUrl($m->media_id, $m->file_name, $m->file_extension, isset($m->uploaded_at) ? (int) $m->uploaded_at : null);
                    $wpImageId = $imageMigrator->migrate($imageUrl, "{$m->file_name}.{$m->file_extension}", '', '', $m->media_id);
                    if ($wpImageId) {
                        $data['image'] = ['id' => $wpImageId];
                    }
                }
            }

            $result = $woo->post("products/{$wooProductId}/variations", $data);
            $wooVariationId = $result['id'] ?? null;

            if ($wooVariationId) {
                // Persist the parent product's WC id alongside the variation mapping so
                // the order migrator can set `product_id` on line items pointing at the
                // variation — WC requires both ids for variation line items.
                $stateManager->set('variation', $variant->id, $wooVariationId, $this->migrationId, [
                    'parent_woo_id' => $wooProductId,
                    'parent_shopware_id' => $variant->parent_id ?? null,
                ]);
                $this->log('info', "Migrated variant '{$variant->sku}' → WC #{$wooVariationId}", $variant->id, 'variation');
            }
        } catch (\Throwable $e) {
            $stateManager->markFailed('variation', $variant->id, $this->migrationId, $e->getMessage());
            $this->log('error', "Variant failed: {$e->getMessage()}", $variant->id, 'variation');
        }
    }

    /**
     * Mark all products in this batch as failed if the job itself exhausts its retries.
     */
    public function failed(Throwable $exception): void
    {
        $stateManager = app(StateManager::class);

        foreach ($this->productIds as $productId) {
            $stateManager->markFailed('product', $productId, $this->migrationId, $exception->getMessage());
        }

        MigrationLog::create([
            'migration_id' => $this->migrationId,
            'entity_type' => 'product',
            'level' => 'error',
            'message' => 'Batch job failed after retries: '.$exception->getMessage(),
            'created_at' => now(),
        ]);
    }

    /**
     * Stamp _remizasklep_delivery_tiers postmeta on the just-created WC product
     * when (a) the operator opted in, (b) WC DB credentials are configured, and
     * (c) the source product carries a non-empty `remiza_shipping_tiers` array.
     *
     * Validation failures bubble out as warnings logged against the product, so
     * a single malformed tier doesn't kill the whole batch — but per the plugin
     * contract we never silently drop a row, we always log it.
     */
    protected function maybeWriteDeliveryTiers(MigrationRun $migration, object $product, int $wooProductId): void
    {
        if (empty($migration->settings['remizasklep_options']['delivery_tiers_enabled'] ?? false)) {
            return;
        }

        if ($migration->is_dry_run) {
            return;
        }

        try {
            $tiers = \App\Shopware\Transformers\DeliveryTierTransformer::extract($product);
        } catch (\Throwable $e) {
            $this->log('warning', "Delivery tiers rejected: {$e->getMessage()}", $product->id);

            return;
        }

        if ($tiers === null) {
            return;
        }

        $db = \App\Services\WooCommerceDB::fromMigration($migration);
        if (! $db->isConfigured()) {
            $this->log('warning', 'Delivery-tier write skipped: WC DB credentials not configured.', $product->id);

            return;
        }

        try {
            $json = json_encode($tiers, JSON_UNESCAPED_UNICODE);
            $db->replacePostMeta('_remizasklep_delivery_tiers', [$wooProductId => $json]);
            $this->log('info', 'Stamped '.count($tiers).' delivery tier(s).', $product->id);
        } catch (\Throwable $e) {
            $this->log('warning', "Delivery-tier write failed: {$e->getMessage()}", $product->id);
        } finally {
            $db->disconnect();
        }
    }

    protected function log(string $level, string $message, ?string $shopwareId = null, ?string $entityType = null): void
    {
        MigrationLog::create([
            'migration_id' => $this->migrationId,
            'entity_type' => $entityType ?? 'product',
            'shopware_id' => $shopwareId,
            'level' => $level,
            'message' => $message,
            'created_at' => now(),
        ]);
    }
}

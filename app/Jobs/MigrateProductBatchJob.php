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

        $globalAttrMap = \App\Models\MigrationEntity::where('migration_id', $this->migrationId)
            ->where('entity_type', 'product_attribute')
            ->whereNotNull('woo_id')
            ->pluck('woo_id', 'shopware_id')
            ->map(fn ($v) => (int) $v)
            ->all();

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
                        $productId, $migration, $db, $woo, $imageMigrator, $reader, $transformer, $stateManager, $globalAttrMap
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
        array $globalAttrMap = [],
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
                $transformer->buildAttributes($configuratorSettings, true, $globalAttrMap),
                $transformer->buildAttributes($properties, false, $globalAttrMap),
            );

            if ($manufacturerAttribute !== null) {
                $attributes[] = $manufacturerAttribute;
            }

            if (! empty($product->delivery_time_name)) {
                $attr = [
                    'name' => 'Delivery time',
                    'options' => [(string) $product->delivery_time_name],
                    'visible' => true,
                    'variation' => false,
                    'position' => count($attributes),
                ];
                if (! empty($globalAttrMap['__delivery_time__'])) {
                    $attr['id'] = (int) $globalAttrMap['__delivery_time__'];
                }
                $attributes[] = $attr;
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

            $blockPurchaseRule = (bool) ($migration->settings['companion_options']['block_purchase_on_closeout'] ?? false);

            if (! empty($product->cms_page_id)) {
                $slotConfigOverrides = json_decode((string) ($product->slot_config ?? '{}'), true);
                if (! is_array($slotConfigOverrides)) {
                    $slotConfigOverrides = [];
                }
                $videoEmbeds = self::renderVideoSlots($reader->fetchVideoSlots($product->cms_page_id), $slotConfigOverrides);
                if ($videoEmbeds !== '') {
                    $product->description = ((string) ($product->description ?? '')).$videoEmbeds;
                }
            }

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
                            $data['default_attributes'] = $transformer->buildVariantOptionAttributes($defaultOptions, $globalAttrMap);
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
                        $optionAttributes = $transformer->buildVariantOptionAttributes($variantOptions, $globalAttrMap);
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

            foreach ($this->resolveSizeChartMeta($product, $reader, $imageMigrator) as $meta) {
                $data['meta_data'][] = $meta;
            }

            // Layout `image` slots (per-product slot_config overrides) appended to the
            // description, skipping any media already shown in the gallery/cover.
            $galleryMediaIds = array_map(static fn ($m) => strtolower((string) $m->media_id), $media);
            $layoutImagesHtml = $this->buildLayoutImagesHtml($product, $reader, $imageMigrator, $galleryMediaIds);
            if ($layoutImagesHtml !== '') {
                $data['description'] = self::stripLayoutImagesBlock((string) ($data['description'] ?? '')).$layoutImagesHtml;
            }

            $result = $woo->createOrFind('products', $data, 'sku', $product->sku);
            $wooProductId = $result['id'] ?? null;

            if (! $wooProductId) {
                throw new \RuntimeException('Failed to create product in WooCommerce');
            }

            $stateManager->set('product', $product->id, $wooProductId, $this->migrationId, [
                'slug' => $result['slug'] ?? null,
            ]);
            $this->log('info', "Migrated product '{$data['name']}' → WC #{$wooProductId}", $product->id);

            $this->maybeWriteDeliveryTiers($migration, $product, $wooProductId);

            foreach ($variants as $variant) {
                $this->migrateVariant($variant, $product->id, $wooProductId, $reader, $transformer, $woo, $imageMigrator, $stateManager, $blockPurchaseRule, $globalAttrMap);
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
        string $parentShopwareId,
        int $wooProductId,
        ProductReader $reader,
        ProductTransformer $transformer,
        WooCommerceClient $woo,
        ImageMigrator $imageMigrator,
        StateManager $stateManager,
        bool $blockPurchaseRule = false,
        array $globalAttrMap = [],
    ): void {
        if ($stateManager->alreadyMigrated('variation', $variant->id, $this->migrationId)) {
            return;
        }

        try {
            $variantOptions = $reader->fetchVariantOptions($variant->id);
            $optionAttributes = $transformer->buildVariantOptionAttributes($variantOptions, $globalAttrMap);
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
                    'parent_shopware_id' => $parentShopwareId,
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

    /** Forwards per-product delivery tiers to the configured companion meta key. */
    protected function maybeWriteDeliveryTiers(MigrationRun $migration, object $product, int $wooProductId): void
    {
        if (empty($migration->settings['companion_options']['delivery_tiers_enabled'] ?? false)) {
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
            $metaKey = (string) config('migration.companion.meta.delivery_tiers');
            $db->replacePostMeta($metaKey, [$wooProductId => $json]);
            $this->log('info', 'Stamped '.count($tiers).' delivery tier(s).', $product->id);
        } catch (\Throwable $e) {
            $this->log('warning', "Delivery-tier write failed: {$e->getMessage()}", $product->id);
        } finally {
            $db->disconnect();
        }
    }

    /**
     * @param  array<int, object>  $slots
     * @param  array<string, mixed>  $slotConfigOverrides  Per-product slot config from product_translation.slot_config, keyed by slot id.
     */
    public static function renderVideoSlots(array $slots, array $slotConfigOverrides = []): string
    {
        $out = '';
        foreach ($slots as $slot) {
            $config = json_decode((string) ($slot->config ?? '{}'), true);
            if (! is_array($config)) {
                continue;
            }

            $slotId = $slot->slot_id ?? null;
            if (is_string($slotId) && isset($slotConfigOverrides[$slotId]) && is_array($slotConfigOverrides[$slotId])) {
                $config = array_replace($config, $slotConfigOverrides[$slotId]);
            }

            $id = $config['videoID']['value'] ?? null;
            if (! is_string($id) || $id === '') {
                continue;
            }
            $id = htmlspecialchars($id, ENT_QUOTES, 'UTF-8');

            if ($slot->type === 'vimeo-video') {
                $url = "https://vimeo.com/{$id}";
                $provider = 'vimeo';
                $blockClass = 'wp-block-embed-vimeo';
            } else {
                $url = "https://www.youtube.com/watch?v={$id}";
                $provider = 'youtube';
                $blockClass = 'wp-block-embed-youtube';
            }

            $attrs = json_encode([
                'url' => $url,
                'type' => 'video',
                'providerNameSlug' => $provider,
                'responsive' => true,
                'className' => 'wp-embed-aspect-16-9 wp-has-aspect-ratio',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $out .= "\n<!-- wp:embed {$attrs} -->\n"
                ."<figure class=\"wp-block-embed is-type-video is-provider-{$provider} {$blockClass} wp-embed-aspect-16-9 wp-has-aspect-ratio\">"
                .'<div class="wp-block-embed__wrapper">'
                ."\n{$url}\n"
                .'</div></figure>'
                ."\n<!-- /wp:embed -->";
        }

        return $out;
    }

    /**
     * Resolve a product's layout `image` slots to an ordered, de-duplicated list of media
     * UUIDs to embed in the description. Per-product slot_config override (media.value) wins
     * over the slot's layout default; images already in the product gallery/cover are skipped.
     *
     * @param  array<int, object>  $slots  ordered slots, each with ->slot_id and ->media (layout default)
     * @param  array<string, mixed>  $overrides  decoded product_translation.slot_config
     * @param  array<int, string>  $galleryMediaIds  lowercase-hex media ids already in the gallery/cover
     * @return array<int, string>
     */
    public static function resolveLayoutImageMediaIds(array $slots, array $overrides, array $galleryMediaIds): array
    {
        $skip = array_flip(array_map('strtolower', $galleryMediaIds));
        $out = [];
        $seen = [];
        foreach ($slots as $slot) {
            $slotId = $slot->slot_id ?? null;

            // A per-product slot_config override wins EXCLUSIVELY: if the product set a media
            // override (even to null, i.e. cleared the image) we honour it and never fall back
            // to the shared layout default. Only when there is no override entry at all do we
            // use the layout default.
            $override = is_string($slotId) && isset($overrides[$slotId]) && is_array($overrides[$slotId])
                ? $overrides[$slotId]
                : null;
            if ($override !== null && array_key_exists('media', $override)) {
                $mediaCfg = $override['media'];
                $mediaId = is_array($mediaCfg) ? ($mediaCfg['value'] ?? null) : null;
            } else {
                $mediaId = $slot->media ?? null;
            }

            if (! is_string($mediaId) || $mediaId === '') {
                continue;
            }
            if (isset($skip[strtolower($mediaId)]) || isset($seen[$mediaId])) {
                continue;
            }
            $seen[$mediaId] = true;
            $out[] = $mediaId;
        }

        return $out;
    }

    /**
     * Render uploaded layout images as Gutenberg wp:image blocks, wrapped in markers so a
     * backfill can strip-and-rebuild idempotently. Returns '' when there are no images.
     *
     * @param  array<int, array{id: int, url: string, alt: string}>  $images
     */
    public static function renderLayoutImagesBlock(array $images): string
    {
        if (empty($images)) {
            return '';
        }

        $out = "\n<!-- sw:layout-images:start -->";
        foreach ($images as $img) {
            $id = (int) $img['id'];
            $url = htmlspecialchars((string) $img['url'], ENT_QUOTES, 'UTF-8');
            $alt = htmlspecialchars((string) ($img['alt'] ?? ''), ENT_QUOTES, 'UTF-8');
            $out .= "\n<!-- wp:image {\"id\":{$id},\"sizeSlug\":\"large\",\"linkDestination\":\"none\"} -->\n"
                ."<figure class=\"wp-block-image size-large\"><img src=\"{$url}\" alt=\"{$alt}\" class=\"wp-image-{$id}\"/></figure>"
                ."\n<!-- /wp:image -->";
        }
        $out .= "\n<!-- sw:layout-images:end -->";

        return $out;
    }

    /**
     * Remove a previously-appended layout-images marker block from a description so the
     * backfill never accumulates duplicates across runs.
     */
    public static function stripLayoutImagesBlock(string $description): string
    {
        return preg_replace('/\n?<!-- sw:layout-images:start -->.*?<!-- sw:layout-images:end -->/s', '', $description) ?? $description;
    }

    /**
     * Extract the size-chart media UUID from a product's custom_fields JSON, or null.
     */
    public static function sizeChartMediaId(?string $customFieldsJson): ?string
    {
        if ($customFieldsJson === null || $customFieldsJson === '') {
            return null;
        }
        $fields = json_decode($customFieldsJson, true);
        if (! is_array($fields)) {
            return null;
        }
        $key = (string) config('migration.size_chart.custom_field');
        $value = $fields[$key] ?? null;

        return (is_string($value) && $value !== '') ? $value : null;
    }

    /**
     * Upload the product's size-chart image (if any) to WordPress and return the
     * meta entries pointing at the uploaded attachment.
     *
     * @return array<int, array{key: string, value: int|string}>
     */
    protected function resolveSizeChartMeta(object $product, ProductReader $reader, ImageMigrator $imageMigrator): array
    {
        $mediaId = self::sizeChartMediaId(is_string($product->custom_fields ?? null) ? $product->custom_fields : null);
        if ($mediaId === null) {
            return [];
        }

        $media = $reader->fetchMediaById($mediaId);
        if ($media === null || empty($media->file_name) || empty($media->file_extension)) {
            $this->log('warning', "Size-chart media {$mediaId} not found or incomplete", $product->id);

            return [];
        }

        $url = $imageMigrator->buildShopwareMediaUrl($media->media_id, $media->file_name, $media->file_extension, isset($media->uploaded_at) ? (int) $media->uploaded_at : null);
        $attachmentId = $imageMigrator->migrate($url, "{$media->file_name}.{$media->file_extension}", $media->title ?? '', $media->alt ?? '', $media->media_id, ['image/', 'application/pdf']);
        if (! $attachmentId) {
            $this->log('warning', "Size-chart image upload failed for media {$mediaId}", $product->id);

            return [];
        }

        $meta = [['key' => (string) config('migration.size_chart.meta.image_id'), 'value' => $attachmentId]];
        $wpUrl = $imageMigrator->getWordPressMediaUrl($attachmentId);
        if ($wpUrl !== null) {
            $meta[] = ['key' => (string) config('migration.size_chart.meta.image_url'), 'value' => $wpUrl];
        }

        return $meta;
    }

    /**
     * Resolve, upload, and render a product's layout `image` slots as a marker-wrapped
     * block of wp:image blocks, ready to append to the description. Returns '' when there
     * are no eligible images.
     *
     * @param  array<int, string>  $galleryMediaIds  lowercase-hex media ids already in the gallery/cover
     */
    protected function buildLayoutImagesHtml(object $product, ProductReader $reader, ImageMigrator $imageMigrator, array $galleryMediaIds): string
    {
        if (empty($product->cms_page_id)) {
            return '';
        }

        $slots = $reader->fetchImageSlots($product->cms_page_id);
        if (empty($slots)) {
            return '';
        }

        $overrides = json_decode(is_string($product->slot_config ?? null) ? $product->slot_config : '{}', true);
        if (! is_array($overrides)) {
            $overrides = [];
        }

        $mediaIds = self::resolveLayoutImageMediaIds($slots, $overrides, $galleryMediaIds);

        $images = [];
        foreach ($mediaIds as $mediaId) {
            $media = $reader->fetchMediaById($mediaId);
            if ($media === null || empty($media->file_name) || empty($media->file_extension)) {
                continue;
            }
            $url = $imageMigrator->buildShopwareMediaUrl($media->media_id, $media->file_name, $media->file_extension, isset($media->uploaded_at) ? (int) $media->uploaded_at : null);
            $attachmentId = $imageMigrator->migrate($url, "{$media->file_name}.{$media->file_extension}", $media->title ?? '', $media->alt ?? '', $media->media_id);
            if (! $attachmentId) {
                $this->log('warning', "Layout image upload failed for media {$mediaId}", $product->id);

                continue;
            }
            $wpUrl = $imageMigrator->getWordPressMediaUrl($attachmentId);
            if ($wpUrl === null) {
                continue;
            }
            $images[] = ['id' => $attachmentId, 'url' => $wpUrl, 'alt' => $media->alt ?? ''];
        }

        return self::renderLayoutImagesBlock($images);
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

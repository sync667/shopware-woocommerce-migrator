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

            $data = $transformer->transform(
                $product,
                $categoryWooIds,
                $manufacturerWooId,
                $taxClassSlug,
                $attributes,
                $tags,
            );

            $variants = $reader->fetchVariants($product->id);
            if (! empty($variants)) {
                $data['type'] = 'variable';
            }

            if ($migration->is_dry_run) {
                $stateManager->markSkipped('product', $product->id, $this->migrationId, $data);
                $this->log('info', "Dry run: product '{$data['name']}'", $product->id);

                foreach ($variants as $variant) {
                    try {
                        $variantOptions = $reader->fetchVariantOptions($variant->id);
                        $optionAttributes = $transformer->buildVariantOptionAttributes($variantOptions);
                        $variantData = $transformer->transformVariant($variant, $optionAttributes);
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
                $wpImageId = $imageMigrator->migrate($imageUrl, "{$m->file_name}.{$m->file_extension}", $m->title ?? '', $m->alt ?? '');
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

            foreach ($variants as $variant) {
                $this->migrateVariant($variant, $wooProductId, $reader, $transformer, $woo, $imageMigrator, $stateManager);
            }

            $crossSells = $reader->fetchCrossSells($product->id);
            if (! empty($crossSells)) {
                $this->migrateCrossSells($crossSells, $wooProductId, $woo, $stateManager);
            }
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
    ): void {
        if ($stateManager->alreadyMigrated('variation', $variant->id, $this->migrationId)) {
            return;
        }

        try {
            $variantOptions = $reader->fetchVariantOptions($variant->id);
            $optionAttributes = $transformer->buildVariantOptionAttributes($variantOptions);
            $data = $transformer->transformVariant($variant, $optionAttributes);

            $media = $reader->fetchMedia($variant->id);
            if (! empty($media)) {
                $m = $media[0];
                if (! empty($m->file_name) && ! empty($m->file_extension)) {
                    $imageUrl = $imageMigrator->buildShopwareMediaUrl($m->media_id, $m->file_name, $m->file_extension, isset($m->uploaded_at) ? (int) $m->uploaded_at : null);
                    $wpImageId = $imageMigrator->migrate($imageUrl, "{$m->file_name}.{$m->file_extension}");
                    if ($wpImageId) {
                        $data['image'] = ['id' => $wpImageId];
                    }
                }
            }

            $result = $woo->post("products/{$wooProductId}/variations", $data);
            $wooVariationId = $result['id'] ?? null;

            if ($wooVariationId) {
                $stateManager->set('variation', $variant->id, $wooVariationId, $this->migrationId);
                $this->log('info', "Migrated variant '{$variant->sku}' → WC #{$wooVariationId}", $variant->id, 'variation');
            }
        } catch (\Throwable $e) {
            $stateManager->markFailed('variation', $variant->id, $this->migrationId, $e->getMessage());
            $this->log('error', "Variant failed: {$e->getMessage()}", $variant->id, 'variation');
        }
    }

    protected function migrateCrossSells(array $crossSells, int $wooProductId, WooCommerceClient $woo, StateManager $stateManager): void
    {
        $upsellIds = [];
        $crossSellIds = [];

        foreach ($crossSells as $cs) {
            $wooTargetId = $stateManager->get('product', $cs->target_product_id, $this->migrationId);
            if (! $wooTargetId) {
                continue;
            }

            if ($cs->type === 'upsell') {
                $upsellIds[] = $wooTargetId;
            } else {
                $crossSellIds[] = $wooTargetId;
            }
        }

        $updateData = [];
        if (! empty($upsellIds)) {
            $updateData['upsell_ids'] = $upsellIds;
        }
        if (! empty($crossSellIds)) {
            $updateData['cross_sell_ids'] = $crossSellIds;
        }

        if (! empty($updateData)) {
            try {
                $woo->put("products/{$wooProductId}", $updateData);
            } catch (\Exception $e) {
                $this->log('warning', "Cross-sell update failed: {$e->getMessage()}");
            }
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

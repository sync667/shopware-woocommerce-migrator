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

class MigrateProductJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 5;

    public function __construct(
        protected int $migrationId,
        protected string $shopwareProductId,
    ) {}

    public function handle(StateManager $stateManager): void
    {
        if ($stateManager->alreadyMigrated('product', $this->shopwareProductId, $this->migrationId)) {
            return;
        }

        if (app(\App\Services\CancellationService::class)->isCancelled($this->migrationId)) {
            $this->batch()?->cancel();

            return;
        }

        $migration = MigrationRun::findOrFail($this->migrationId);
        $db = ShopwareDB::fromMigration($migration);
        $woo = WooCommerceClient::fromMigration($migration);
        $imageMigrator = ImageMigrator::fromMigration($migration);
        $contentMigrator = new ContentMigrator($imageMigrator);
        $reader = new ProductReader($db);
        $transformer = new ProductTransformer($contentMigrator);

        $products = $db->select("
            SELECT
                LOWER(HEX(p.id)) AS id,
                LOWER(HEX(p.parent_id)) AS parent_id,
                p.product_number AS sku,
                p.active, p.stock, p.is_closeout AS manage_stock,
                p.weight, p.width, p.height, p.length AS depth,
                p.price, CASE WHEN p.child_count > 0 THEN 'grouped' ELSE 'simple' END AS type,
                LOWER(HEX(p.tax_id)) AS tax_id,
                LOWER(HEX(p.product_manufacturer_id)) AS manufacturer_id,
                LOWER(HEX(p.product_media_id)) AS cover_id,
                COALESCE(pt.name, '') AS name,
                COALESCE(pt.description, '') AS description,
                COALESCE(pt.meta_title, '') AS meta_title,
                COALESCE(pt.meta_description, '') AS meta_description
            FROM product p
            LEFT JOIN product_translation pt
                ON pt.product_id = p.id
                AND pt.product_version_id = p.version_id
                AND pt.language_id = ?
            WHERE p.id = UNHEX(?)
              AND p.version_id = ?
        ", [$db->languageIdBin(), $this->shopwareProductId, $db->liveVersionIdBin()]);

        if (empty($products)) {
            $this->log('warning', 'Product not found in Shopware');

            return;
        }

        $product = $products[0];

        try {
            $categoryWooIds = [];
            foreach ($reader->fetchCategories($product->id) as $cat) {
                $wooId = $stateManager->get('category', $cat->category_id, $this->migrationId);
                if ($wooId) {
                    $categoryWooIds[] = $wooId;
                }
            }

            $manufacturerWooId = null;
            if (! empty($product->manufacturer_id)) {
                $manufacturerWooId = $stateManager->get('manufacturer', $product->manufacturer_id, $this->migrationId);
            }

            $taxClassSlug = '';
            if (! empty($product->tax_id)) {
                $taxMap = $stateManager->getMap('tax', $this->migrationId);
                if (isset($taxMap[$product->tax_id])) {
                    $taxClassSlug = (string) $taxMap[$product->tax_id];
                }
            }

            $configuratorSettings = $reader->fetchConfiguratorSettings($product->id);
            $properties = $reader->fetchProperties($product->id);
            $tags = array_map(fn ($t) => $t->name, $reader->fetchTags($product->id));

            $attributes = array_merge(
                $transformer->buildAttributes($configuratorSettings, true),
                $transformer->buildAttributes($properties, false),
            );

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
                $stateManager->markPending('product', $product->id, $this->migrationId, $data);
                $this->log('info', "Dry run: product '{$data['name']}'");

                return;
            }

            $media = $reader->fetchMedia($product->id);
            $imageIds = [];
            foreach ($media as $m) {
                if (empty($m->file_name) || empty($m->file_extension)) {
                    continue;
                }
                $imageUrl = $imageMigrator->buildShopwareMediaUrl($m->path, $m->file_name, $m->file_extension);
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
                        $data['images'] = array_merge([['id' => $img['id']]], $data['images'] ?? []);
                        $featuredSet = true;
                    } else {
                        $data['images'][] = ['id' => $img['id']];
                    }
                }
                if (! $featuredSet && ! empty($imageIds)) {
                    array_unshift($data['images'], ['id' => $imageIds[0]['id']]);
                }
            }

            $result = $woo->createOrFind('products', $data, 'sku', $product->sku);
            $wooProductId = $result['id'] ?? null;

            if (! $wooProductId) {
                throw new \RuntimeException('Failed to create product in WooCommerce');
            }

            $stateManager->set('product', $product->id, $wooProductId, $this->migrationId);
            $this->log('info', "Migrated product '{$data['name']}' → WC #{$wooProductId}");

            foreach ($variants as $variant) {
                $this->migrateVariant($variant, $wooProductId, $reader, $transformer, $woo, $imageMigrator, $stateManager);
            }

            $crossSells = $reader->fetchCrossSells($product->id);
            if (! empty($crossSells)) {
                $this->migrateCrossSells($crossSells, $wooProductId, $woo, $stateManager);
            }
        } catch (\Exception $e) {
            $stateManager->markFailed('product', $product->id, $this->migrationId, $e->getMessage());
            $this->log('error', "Failed: {$e->getMessage()}");
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
                    $imageUrl = $imageMigrator->buildShopwareMediaUrl($m->path, $m->file_name, $m->file_extension);
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
        } catch (\Exception $e) {
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

    protected function log(string $level, string $message, ?string $shopwareId = null, ?string $entityType = null): void
    {
        MigrationLog::create([
            'migration_id' => $this->migrationId,
            'entity_type' => $entityType ?? 'product',
            'shopware_id' => $shopwareId ?? $this->shopwareProductId,
            'level' => $level,
            'message' => $message,
            'created_at' => now(),
        ]);
    }
}

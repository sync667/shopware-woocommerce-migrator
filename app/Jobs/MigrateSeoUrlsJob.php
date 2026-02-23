<?php

namespace App\Jobs;

use App\Models\MigrationLog;
use App\Models\MigrationRun;
use App\Services\ShopwareDB;
use App\Services\StateManager;
use App\Shopware\Readers\SeoUrlReader;
use App\Shopware\Transformers\SeoUrlTransformer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class MigrateSeoUrlsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 5;

    public function __construct(protected int $migrationId) {}

    public function handle(StateManager $stateManager): void
    {
        $migration = MigrationRun::findOrFail($this->migrationId);
        $db = ShopwareDB::fromMigration($migration);
        $reader = new SeoUrlReader($db);
        $transformer = new SeoUrlTransformer;

        // Migrate product SEO URLs
        $productSeoUrls = $reader->fetchAllForProducts();
        foreach ($productSeoUrls as $seoUrl) {
            if ($stateManager->alreadyMigrated('seo_url', $seoUrl->id, $this->migrationId)) {
                continue;
            }

            try {
                // Get the new WooCommerce product URL from state
                $productWooId = $stateManager->get('product', $seoUrl->foreign_key, $this->migrationId);
                $newUrl = $productWooId ? "/product/{$productWooId}" : null;

                $data = $transformer->transform($seoUrl, $newUrl);

                if ($migration->is_dry_run) {
                    $stateManager->markPending('seo_url', $seoUrl->id, $this->migrationId, $data);
                    $this->log('info', "Dry run: SEO URL '{$data['old_url']}' → '{$data['new_url']}'", $seoUrl->id, 'seo_url');

                    continue;
                }

                $stateManager->set('seo_url', $seoUrl->id, crc32($data['old_url']), $this->migrationId, $data);
                $this->log('info', "Migrated SEO URL '{$data['old_url']}'", $seoUrl->id, 'seo_url');
            } catch (\Exception $e) {
                $stateManager->markFailed('seo_url', $seoUrl->id, $this->migrationId, $e->getMessage());
                $this->log('error', "Failed: {$e->getMessage()}", $seoUrl->id, 'seo_url');
            }
        }

        // Migrate category SEO URLs
        $categorySeoUrls = $reader->fetchAllForCategories();
        foreach ($categorySeoUrls as $seoUrl) {
            if ($stateManager->alreadyMigrated('seo_url', $seoUrl->id, $this->migrationId)) {
                continue;
            }

            try {
                // Get the new WooCommerce category URL from state
                $categoryWooId = $stateManager->get('category', $seoUrl->foreign_key, $this->migrationId);
                $newUrl = $categoryWooId ? "/product-category/{$categoryWooId}" : null;

                $data = $transformer->transform($seoUrl, $newUrl);

                if ($migration->is_dry_run) {
                    $stateManager->markPending('seo_url', $seoUrl->id, $this->migrationId, $data);
                    $this->log('info', "Dry run: SEO URL '{$data['old_url']}' → '{$data['new_url']}'", $seoUrl->id, 'seo_url');

                    continue;
                }

                $stateManager->set('seo_url', $seoUrl->id, crc32($data['old_url']), $this->migrationId, $data);
                $this->log('info', "Migrated category SEO URL '{$data['old_url']}'", $seoUrl->id, 'seo_url');
            } catch (\Exception $e) {
                $stateManager->markFailed('seo_url', $seoUrl->id, $this->migrationId, $e->getMessage());
                $this->log('error', "Failed: {$e->getMessage()}", $seoUrl->id, 'seo_url');
            }
        }
    }

    protected function log(string $level, string $message, ?string $shopwareId = null, ?string $entityType = null): void
    {
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

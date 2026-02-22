<?php

namespace App\Jobs;

use App\Models\MigrationLog;
use App\Models\MigrationRun;
use App\Services\ImageMigrator;
use App\Services\ShopwareDB;
use App\Services\StateManager;
use App\Services\WooCommerceClient;
use App\Shopware\Readers\CategoryReader;
use App\Shopware\Transformers\CategoryTransformer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class MigrateCategoriesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 5;

    public function __construct(protected int $migrationId) {}

    public function handle(StateManager $stateManager): void
    {
        $migration = MigrationRun::findOrFail($this->migrationId);
        $db = ShopwareDB::fromMigration($migration);
        $woo = WooCommerceClient::fromMigration($migration);
        $imageMigrator = ImageMigrator::fromMigration($migration);
        $reader = new CategoryReader($db);
        $transformer = new CategoryTransformer;

        $categories = $reader->fetchAll();

        foreach ($categories as $category) {
            if ($stateManager->alreadyMigrated('category', $category->id, $this->migrationId)) {
                continue;
            }

            try {
                $wooParentId = null;
                if (! empty($category->parent_id)) {
                    $wooParentId = $stateManager->get('category', $category->parent_id, $this->migrationId);
                }

                $data = $transformer->transform($category, $wooParentId);

                if ($migration->is_dry_run) {
                    $stateManager->markPending('category', $category->id, $this->migrationId, $data);
                    $this->log('info', "Dry run: category '{$data['name']}'", $category->id, 'category');

                    continue;
                }

                if (! empty($category->media_file_name) && ! empty($category->media_file_extension)) {
                    $imageUrl = $imageMigrator->buildShopwareMediaUrl(
                        $category->media_path,
                        $category->media_file_name,
                        $category->media_file_extension
                    );
                    $wpImageId = $imageMigrator->migrate(
                        $imageUrl,
                        $category->media_file_name.'.'.$category->media_file_extension,
                        $category->name
                    );
                    if ($wpImageId) {
                        $data['image'] = ['id' => $wpImageId];
                    }
                }

                $slug = Str::slug($data['name'] ?? '');
                $result = $woo->createOrFind('products/categories', $data, 'slug', $slug ?: 'category');
                $wooId = $result['id'] ?? null;

                if ($wooId) {
                    $stateManager->set('category', $category->id, $wooId, $this->migrationId);
                    $this->log('info', "Migrated category '{$data['name']}' → WC #{$wooId}", $category->id, 'category');
                }
            } catch (\Exception $e) {
                $stateManager->markFailed('category', $category->id, $this->migrationId, $e->getMessage());
                $this->log('error', "Failed: {$e->getMessage()}", $category->id, 'category');
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

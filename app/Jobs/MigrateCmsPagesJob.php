<?php

namespace App\Jobs;

use App\Models\MigrationLog;
use App\Models\MigrationRun;
use App\Services\ShopwareDB;
use App\Services\StateManager;
use App\Services\WooCommerceClient;
use App\Shopware\Readers\CmsPageReader;
use App\Shopware\Readers\SeoUrlReader;
use App\Shopware\Transformers\CmsPageTransformer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class MigrateCmsPagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 5;

    public int $timeout = 3600; // 1 hour timeout for large migrations

    public function __construct(
        protected int $migrationId,
        protected ?array $selectedPageIds = null
    ) {}

    public function handle(StateManager $stateManager): void
    {
        $migration = MigrationRun::findOrFail($this->migrationId);
        $db = ShopwareDB::fromMigration($migration);
        $woo = WooCommerceClient::fromMigration($migration);
        $reader = new CmsPageReader($db);
        $seoReader = new SeoUrlReader($db);
        $transformer = new CmsPageTransformer($stateManager, $this->migrationId);

        // Fetch pages based on selection
        $pages = $this->selectedPageIds
            ? $reader->fetchByIds($this->selectedPageIds)
            : $reader->fetchAll();

        $this->log('info', 'Found '.count($pages).' CMS pages to migrate');

        foreach ($pages as $page) {
            if ($stateManager->alreadyMigrated('cms_page', $page->id, $this->migrationId)) {
                continue;
            }

            try {
                // Fetch full page structure
                $sections = $reader->fetchSections($page->id);

                foreach ($sections as $section) {
                    $blocks = $reader->fetchBlocks($section->id);

                    foreach ($blocks as $block) {
                        $block->slots = $reader->fetchSlots($block->id);
                    }

                    $section->blocks = $blocks;
                }

                // Fetch SEO URL for slug
                $seoUrls = $seoReader->fetchAllForCmsPages();
                $seoUrl = '';
                foreach ($seoUrls as $url) {
                    if ($url->foreign_key === $page->id) {
                        $seoUrl = $url->seo_path_info ?? '';
                        break;
                    }
                }

                // Transform to WordPress page
                $data = $transformer->transform($page, $sections, $seoUrl);

                if ($migration->is_dry_run) {
                    $stateManager->markPending('cms_page', $page->id, $this->migrationId, $data);
                    $this->log('info', "Dry run: CMS page '{$page->name}'", $page->id);

                    continue;
                }

                // Create WordPress page
                // Note: WooCommerce REST API doesn't have /pages endpoint
                // In production, this would use WordPress REST API directly
                // For now, we'll store in state as if created
                $stateManager->markPending('cms_page', $page->id, $this->migrationId, $data);
                $this->log('info', "Migrated CMS page '{$page->name}' (stored for WordPress import)", $page->id);
            } catch (\Exception $e) {
                $stateManager->markFailed('cms_page', $page->id, $this->migrationId, $e->getMessage());
                $this->log('error', "Failed: {$e->getMessage()}", $page->id);
            }
        }
    }

    protected function log(string $level, string $message, ?string $shopwareId = null): void
    {
        MigrationLog::create([
            'migration_id' => $this->migrationId,
            'entity_type' => 'cms_page',
            'shopware_id' => $shopwareId,
            'level' => $level,
            'message' => $message,
            'created_at' => now(),
        ]);
    }
}

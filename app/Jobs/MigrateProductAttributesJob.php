<?php

namespace App\Jobs;

use App\Models\MigrationLog;
use App\Models\MigrationRun;
use App\Services\ShopwareDB;
use App\Services\StateManager;
use App\Services\WooCommerceClient;
use App\Shopware\Readers\ProductReader;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class MigrateProductAttributesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 5;

    public int $timeout = 1800;

    public function __construct(protected int $migrationId)
    {
        $this->onQueue('migration');
    }

    public function handle(StateManager $stateManager): void
    {
        $migration = MigrationRun::findOrFail($this->migrationId);
        $db = ShopwareDB::fromMigration($migration);
        $reader = new ProductReader($db);

        try {
            $groups = $reader->fetchAllPropertyGroups();
        } finally {
            $db->disconnect();
        }

        $this->log('info', 'Pre-registering '.count($groups).' global product attribute(s).');

        if ($migration->is_dry_run) {
            foreach ($groups as $g) {
                $stateManager->markSkipped('product_attribute', $g->group_id, $this->migrationId, [
                    'name' => $g->group_name,
                ]);
            }

            return;
        }

        $woo = WooCommerceClient::fromMigration($migration);

        [$existingByName, $existingBySlug] = $this->loadExistingAttributes($woo);

        $created = 0;
        $reused = 0;
        $failed = 0;

        foreach ($groups as $g) {
            $name = trim((string) ($g->group_name ?? ''));
            if ($name === '') {
                continue;
            }

            if ($stateManager->alreadyMigrated('product_attribute', $g->group_id, $this->migrationId)) {
                continue;
            }

            try {
                $wasCached = isset($existingByName[mb_strtolower($name)])
                    || isset($existingBySlug[self::truncatedSlug($name)]);
                $wooAttrId = $this->resolveOrCreate($woo, $name, $existingByName, $existingBySlug);
                if (! $wooAttrId) {
                    $failed++;
                    $this->log('warning', "No id returned for global attribute '{$name}'.", $g->group_id);

                    continue;
                }

                $stateManager->set('product_attribute', $g->group_id, $wooAttrId, $this->migrationId, [
                    'name' => $name,
                ]);

                if ($wasCached) {
                    $reused++;
                } else {
                    $existingByName[mb_strtolower($name)] = $wooAttrId;
                    $existingBySlug[self::truncatedSlug($name)] = $wooAttrId;
                    $created++;
                }
            } catch (\Throwable $e) {
                $failed++;
                $stateManager->markFailed('product_attribute', $g->group_id, $this->migrationId, $e->getMessage());
                $this->log('warning', "Global attribute '{$name}' failed: {$e->getMessage()}", $g->group_id);
            }
        }

        try {
            $deliveryId = $this->resolveOrCreate($woo, 'Delivery time', $existingByName, $existingBySlug);
            if ($deliveryId) {
                $stateManager->set('product_attribute', '__delivery_time__', $deliveryId, $this->migrationId, [
                    'name' => 'Delivery time',
                ]);
            }
        } catch (\Throwable $e) {
            $this->log('warning', "Global attribute 'Delivery time' failed: {$e->getMessage()}", '__delivery_time__');
        }

        $this->log('info', "Global attributes done: {$created} created, {$reused} reused existing, {$failed} failures.");
    }

    /**
     * @return array{0: array<string, int>, 1: array<string, int>}
     *                                                             [lowercased name → wooAttrId, slug → wooAttrId]
     */
    protected function loadExistingAttributes(WooCommerceClient $woo): array
    {
        $byName = [];
        $bySlug = [];
        try {
            $page = 1;
            do {
                $rows = $woo->get('products/attributes', ['per_page' => 100, 'page' => $page]);
                if (! is_array($rows) || $rows === []) {
                    break;
                }
                foreach ($rows as $row) {
                    if (! isset($row['id'])) {
                        continue;
                    }
                    $id = (int) $row['id'];
                    $name = (string) ($row['name'] ?? '');
                    $slug = (string) ($row['slug'] ?? '');
                    if ($name !== '') {
                        $byName[mb_strtolower($name)] = $id;
                    }
                    // WC sometimes returns slug with the legacy "pa_" prefix; normalize so
                    // our truncatedSlug() lookups match cleanly.
                    if ($slug !== '') {
                        $bySlug[preg_replace('/^pa_/', '', $slug)] = $id;
                    }
                }
                $page++;
            } while (count($rows) === 100);
        } catch (\Throwable $e) {
            $this->log('warning', "Could not pre-load existing WC attributes: {$e->getMessage()}");
        }

        return [$byName, $bySlug];
    }

    /**
     * @param  array<string, int>  $existingByName
     * @param  array<string, int>  $existingBySlug
     */
    protected function resolveOrCreate(WooCommerceClient $woo, string $name, array $existingByName, array $existingBySlug): ?int
    {
        $nameKey = mb_strtolower($name);
        if (isset($existingByName[$nameKey])) {
            return $existingByName[$nameKey];
        }

        // Different source names can slugify to the same WC slug ("Moc światła." vs
        // "Moc światła"). The first POST wins; later names with the colliding slug
        // must reuse the existing attribute rather than 400 on us.
        $slugKey = self::truncatedSlug($name);
        if (isset($existingBySlug[$slugKey])) {
            return $existingBySlug[$slugKey];
        }

        $result = $woo->post('products/attributes', [
            'name' => $name,
            'slug' => $slugKey,
            'type' => 'select',
            'has_archives' => true,
        ]);

        $id = $result['id'] ?? null;

        return $id ? (int) $id : null;
    }

    /**
     * WC limits attribute slugs to 28 chars. Long Polish attribute names slugify
     * past that and POST fails with woocommerce_rest_cannot_create. Truncate to
     * 22 chars + crc32(name) suffix → exactly 28 chars; the hash keeps slugs
     * distinct when two source names truncate to the same prefix.
     */
    public static function truncatedSlug(string $name): string
    {
        $slug = \Illuminate\Support\Str::slug($name, '-');
        if ($slug === '') {
            $slug = 'attr-'.substr(sha1($name), 0, 8);
        }

        if (strlen($slug) <= 28) {
            return $slug;
        }

        $suffix = '-'.substr(sprintf('%x', crc32($name)), 0, 5);

        return substr($slug, 0, 28 - strlen($suffix)).$suffix;
    }

    protected function log(string $level, string $message, ?string $shopwareId = null): void
    {
        MigrationLog::create([
            'migration_id' => $this->migrationId,
            'entity_type' => 'product_attribute',
            'shopware_id' => $shopwareId,
            'level' => $level,
            'message' => $message,
            'created_at' => now(),
        ]);
    }
}

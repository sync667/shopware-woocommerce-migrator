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

        $existingByName = $this->loadExistingAttributesByName($woo);

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
                $wooAttrId = $this->resolveOrCreate($woo, $name, $existingByName);
                if (! $wooAttrId) {
                    $failed++;
                    $this->log('warning', "No id returned for global attribute '{$name}'.", $g->group_id);

                    continue;
                }

                $stateManager->set('product_attribute', $g->group_id, $wooAttrId, $this->migrationId, [
                    'name' => $name,
                ]);

                if (isset($existingByName[mb_strtolower($name)])) {
                    $reused++;
                } else {
                    $existingByName[mb_strtolower($name)] = $wooAttrId;
                    $created++;
                }
            } catch (\Throwable $e) {
                $failed++;
                $stateManager->markFailed('product_attribute', $g->group_id, $this->migrationId, $e->getMessage());
                $this->log('warning', "Global attribute '{$name}' failed: {$e->getMessage()}", $g->group_id);
            }
        }

        try {
            $deliveryId = $this->resolveOrCreate($woo, 'Delivery time', $existingByName);
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

    /** @return array<string, int> lowercased name → wooAttrId */
    protected function loadExistingAttributesByName(WooCommerceClient $woo): array
    {
        $map = [];
        try {
            $page = 1;
            do {
                $rows = $woo->get('products/attributes', ['per_page' => 100, 'page' => $page]);
                if (! is_array($rows) || $rows === []) {
                    break;
                }
                foreach ($rows as $row) {
                    $n = mb_strtolower((string) ($row['name'] ?? ''));
                    if ($n !== '' && isset($row['id'])) {
                        $map[$n] = (int) $row['id'];
                    }
                }
                $page++;
            } while (count($rows) === 100);
        } catch (\Throwable $e) {
            $this->log('warning', "Could not pre-load existing WC attributes: {$e->getMessage()}");
        }

        return $map;
    }

    /** @param  array<string, int>  $existingByName */
    protected function resolveOrCreate(WooCommerceClient $woo, string $name, array $existingByName): ?int
    {
        $key = mb_strtolower($name);
        if (isset($existingByName[$key])) {
            return $existingByName[$key];
        }

        $result = $woo->post('products/attributes', [
            'name' => $name,
            'type' => 'select',
            'has_archives' => true,
        ]);

        $id = $result['id'] ?? null;

        return $id ? (int) $id : null;
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

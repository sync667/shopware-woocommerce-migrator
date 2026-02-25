<?php

namespace App\Jobs;

use App\Models\MigrationLog;
use App\Models\MigrationRun;
use App\Services\ShopwareDB;
use App\Services\StateManager;
use App\Services\WooCommerceClient;
use App\Shopware\Readers\ProductStreamReader;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class MigrateProductStreamsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 5;

    public int $timeout = 3600;

    public function __construct(protected int $migrationId) {}

    public function handle(StateManager $stateManager): void
    {
        $migration = MigrationRun::findOrFail($this->migrationId);
        $db = ShopwareDB::fromMigration($migration);
        $woo = WooCommerceClient::fromMigration($migration);
        $reader = new ProductStreamReader($db);

        $streams = $reader->fetchAll();

        $this->log('info', 'Found '.count($streams).' product streams to migrate');

        foreach ($streams as $stream) {
            if ($stateManager->alreadyMigrated('product_stream', $stream->id, $this->migrationId)) {
                continue;
            }

            try {
                $categoryData = [
                    'name' => $stream->name ?: 'Stream '.$stream->id,
                    'meta_data' => [
                        ['key' => '_shopware_stream_id', 'value' => $stream->id],
                    ],
                ];

                if ($migration->is_dry_run) {
                    $streamProducts = $reader->fetchStreamProducts($stream->id);
                    $stateManager->markSkipped('product_stream', $stream->id, $this->migrationId, array_merge($categoryData, ['product_count' => count($streamProducts)]));
                    $this->log('info', "Dry run: product stream '{$stream->name}' (".count($streamProducts).' products)', $stream->id);

                    continue;
                }

                $result = $woo->createOrFind('products/categories', $categoryData, 'name', $categoryData['name']);
                $wooCategoryId = $result['id'] ?? null;

                if (! $wooCategoryId) {
                    throw new \RuntimeException("Failed to create WC category for stream '{$stream->name}'");
                }

                $stateManager->set('product_stream', $stream->id, $wooCategoryId, $this->migrationId, ['name' => $stream->name]);

                $streamProducts = $reader->fetchStreamProducts($stream->id);
                $assignedCount = 0;

                foreach ($streamProducts as $sp) {
                    $wooProductId = $stateManager->get('product', $sp->product_id, $this->migrationId);
                    if (! $wooProductId) {
                        continue;
                    }

                    try {
                        $product = $woo->get("products/{$wooProductId}");
                        $existingCategories = array_map(fn ($c) => ['id' => $c['id']], $product['categories'] ?? []);

                        $alreadyHas = array_filter($existingCategories, fn ($c) => $c['id'] === $wooCategoryId);
                        if (! empty($alreadyHas)) {
                            $assignedCount++;

                            continue;
                        }

                        $existingCategories[] = ['id' => $wooCategoryId];
                        $woo->put("products/{$wooProductId}", ['categories' => $existingCategories]);
                        $assignedCount++;
                    } catch (\Throwable $e) {
                        $this->log('warning', "Failed to assign product {$sp->product_id} to stream '{$stream->name}': {$e->getMessage()}", $stream->id);
                    }
                }

                $this->log('info', "Stream '{$stream->name}' → WC category #{$wooCategoryId}, assigned {$assignedCount}/".count($streamProducts).' products', $stream->id);
            } catch (\Throwable $e) {
                $stateManager->markFailed('product_stream', $stream->id, $this->migrationId, $e->getMessage());
                $this->log('error', "Failed: {$e->getMessage()}", $stream->id);
            }
        }

        $db->disconnect();
    }

    protected function log(string $level, string $message, ?string $shopwareId = null): void
    {
        MigrationLog::create([
            'migration_id' => $this->migrationId,
            'entity_type' => 'product_stream',
            'shopware_id' => $shopwareId,
            'level' => $level,
            'message' => $message,
            'created_at' => now(),
        ]);
    }
}

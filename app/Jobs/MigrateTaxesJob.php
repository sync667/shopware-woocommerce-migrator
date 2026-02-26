<?php

namespace App\Jobs;

use App\Models\MigrationLog;
use App\Models\MigrationRun;
use App\Services\ShopwareDB;
use App\Services\StateManager;
use App\Services\WooCommerceClient;
use App\Shopware\Readers\TaxReader;
use App\Shopware\Transformers\TaxTransformer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class MigrateTaxesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 5;

    public int $timeout = 3600; // 1 hour timeout for large migrations

    public function __construct(protected int $migrationId) {}

    public function handle(StateManager $stateManager): void
    {
        $migration = MigrationRun::findOrFail($this->migrationId);
        $db = ShopwareDB::fromMigration($migration);
        $woo = WooCommerceClient::fromMigration($migration);
        $reader = new TaxReader($db);
        $transformer = new TaxTransformer;

        $taxes = $reader->fetchAll();

        foreach ($taxes as $tax) {
            if (app(\App\Services\CancellationService::class)->isCancelled($this->migrationId)) {
                return;
            }

            if ($stateManager->alreadyMigrated('tax', $tax->id, $this->migrationId)) {
                continue;
            }

            try {
                $data = $transformer->transform($tax);

                if ($migration->is_dry_run) {
                    $stateManager->markSkipped('tax', $tax->id, $this->migrationId, $data);
                    $this->log('info', "Dry run: tax '{$data['name']}' ({$data['rate']}%)", $tax->id, 'tax');

                    continue;
                }

                $classSlug = $this->ensureTaxClass($woo, $data['name']);

                $rules = $reader->fetchRules($tax->id);
                foreach ($rules as $rule) {
                    $ruleData = $transformer->transformRule($rule, $data['name'], $classSlug);
                    $woo->post('taxes', $ruleData);
                }

                $stateManager->set('tax', $tax->id, crc32($classSlug), $this->migrationId, ['class_slug' => $classSlug]);
                $this->log('info', "Migrated tax '{$data['name']}'", $tax->id, 'tax');
            } catch (\Exception $e) {
                $stateManager->markFailed('tax', $tax->id, $this->migrationId, $e->getMessage());
                $this->log('error', "Failed: {$e->getMessage()}", $tax->id, 'tax');
            }
        }

        $db->disconnect();
    }

    /**
     * Create a WooCommerce tax class if it doesn't exist and return its slug.
     * For the default "standard" rate class, WooCommerce uses an empty string.
     */
    protected function ensureTaxClass(WooCommerceClient $woo, string $name): string
    {
        $existing = $woo->get('taxes/classes');
        foreach ($existing as $class) {
            if (strtolower($class['name']) === strtolower($name)) {
                return $class['slug'];
            }
        }

        $created = $woo->post('taxes/classes', ['name' => $name]);

        return $created['slug'];
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

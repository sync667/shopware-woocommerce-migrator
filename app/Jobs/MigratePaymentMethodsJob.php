<?php

namespace App\Jobs;

use App\Models\MigrationLog;
use App\Models\MigrationRun;
use App\Services\ShopwareDB;
use App\Services\StateManager;
use App\Shopware\Readers\PaymentMethodReader;
use App\Shopware\Transformers\PaymentMethodTransformer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class MigratePaymentMethodsJob implements ShouldQueue
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
        $reader = new PaymentMethodReader($db);
        $transformer = new PaymentMethodTransformer;

        // Delta migration: only fetch updated records
        if ($migration->sync_mode === 'delta' && $migration->last_sync_at) {
            $paymentMethods = $reader->fetchUpdatedSince($migration->last_sync_at);
        } else {
            $paymentMethods = $reader->fetchAll();
        }

        foreach ($paymentMethods as $method) {
            if (app(\App\Services\CancellationService::class)->isCancelled($this->migrationId)) {
                return;
            }

            if ($stateManager->alreadyMigrated('payment_method', $method->id, $this->migrationId)) {
                continue;
            }

            try {
                $data = $transformer->transform($method);

                if ($migration->is_dry_run) {
                    $stateManager->markSkipped('payment_method', $method->id, $this->migrationId, $data);
                    $this->log('info', "Dry run: payment method '{$data['title']}'", $method->id, 'payment_method');

                    continue;
                }

                // Store as meta data for manual configuration in WooCommerce
                $stateManager->set('payment_method', $method->id, crc32($data['id']), $this->migrationId, $data);
                $this->log('info', "Migrated payment method '{$data['title']}'", $method->id, 'payment_method');
            } catch (\Exception $e) {
                $stateManager->markFailed('payment_method', $method->id, $this->migrationId, $e->getMessage());
                $this->log('error', "Failed: {$e->getMessage()}", $method->id, 'payment_method');
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

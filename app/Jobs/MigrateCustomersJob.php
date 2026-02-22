<?php

namespace App\Jobs;

use App\Models\MigrationLog;
use App\Models\MigrationRun;
use App\Services\PasswordMigrator;
use App\Services\ShopwareDB;
use App\Services\StateManager;
use App\Services\WooCommerceClient;
use App\Shopware\Readers\CustomerReader;
use App\Shopware\Transformers\CustomerTransformer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class MigrateCustomersJob implements ShouldQueue
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
        $reader = new CustomerReader($db);
        $transformer = new CustomerTransformer;
        $passwordMigrator = new PasswordMigrator;

        $customers = $reader->fetchAll();

        foreach ($customers as $customer) {
            if ($stateManager->alreadyMigrated('customer', $customer->id, $this->migrationId)) {
                continue;
            }

            try {
                $billingAddress = ! empty($customer->billing_address_id)
                    ? $reader->fetchAddress($customer->billing_address_id)
                    : null;

                $shippingAddress = ! empty($customer->shipping_address_id)
                    ? $reader->fetchAddress($customer->shipping_address_id)
                    : null;

                $data = $transformer->transform($customer, $billingAddress, $shippingAddress);

                if ($migration->is_dry_run) {
                    $stateManager->markPending('customer', $customer->id, $this->migrationId, $data);
                    $this->log('info', "Dry run: customer '{$customer->email}'", $customer->id);

                    continue;
                }

                $result = $woo->createOrFind('customers', $data, 'email', $customer->email);
                $wooId = $result['id'] ?? null;

                if ($wooId) {
                    $stateManager->set('customer', $customer->id, $wooId, $this->migrationId);

                    $passwordResult = $passwordMigrator->migrate($customer->password ?? '', 68);
                    $metaData = [];
                    if ($passwordResult['requires_reset']) {
                        $metaData[] = ['key' => '_requires_password_reset', 'value' => '1'];
                    } else {
                        $metaData[] = ['key' => '_shopware_password_migrated', 'value' => '1'];
                    }

                    if (! empty($metaData)) {
                        try {
                            $woo->put("customers/{$wooId}", ['meta_data' => $metaData]);
                        } catch (\Exception $e) {
                            $this->log('warning', "Meta update failed: {$e->getMessage()}", $customer->id);
                        }
                    }

                    $this->log('info', "Migrated customer '{$customer->email}' → WC #{$wooId}", $customer->id);
                }
            } catch (\Exception $e) {
                $stateManager->markFailed('customer', $customer->id, $this->migrationId, $e->getMessage());
                $this->log('error', "Failed: {$e->getMessage()}", $customer->id);
            }
        }
    }

    protected function log(string $level, string $message, ?string $shopwareId = null): void
    {
        MigrationLog::create([
            'migration_id' => $this->migrationId,
            'entity_type' => 'customer',
            'shopware_id' => $shopwareId,
            'level' => $level,
            'message' => $message,
            'created_at' => now(),
        ]);
    }
}

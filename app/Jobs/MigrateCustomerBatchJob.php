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
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class MigrateCustomerBatchJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 5;

    public int $timeout = 300; // 5 minutes per batch

    public function __construct(
        protected int $migrationId,
        protected array $customerIds
    ) {}

    public function handle(StateManager $stateManager): void
    {
        $migration = MigrationRun::findOrFail($this->migrationId);

        if (app(\App\Services\CancellationService::class)->isCancelled($this->migrationId)) {
            $this->batch()?->cancel();

            return;
        }

        $db = ShopwareDB::fromMigration($migration);
        $woo = WooCommerceClient::fromMigration($migration);
        $reader = new CustomerReader($db);
        $transformer = new CustomerTransformer;
        $passwordMigrator = new PasswordMigrator;

        foreach ($this->customerIds as $customerId) {
            if ($stateManager->alreadyMigrated('customer', $customerId, $this->migrationId)) {
                continue;
            }

            try {
                // Fetch single customer by ID
                $customers = $db->select('
                    SELECT
                        LOWER(HEX(c.id)) AS id,
                        c.first_name,
                        c.last_name,
                        c.email,
                        c.password,
                        c.active,
                        c.customer_number,
                        c.company,
                        c.guest,
                        c.newsletter,
                        LOWER(HEX(c.default_billing_address_id)) AS billing_address_id,
                        LOWER(HEX(c.default_shipping_address_id)) AS shipping_address_id
                    FROM customer c
                    WHERE LOWER(HEX(c.id)) = ?
                ', [$customerId]);

                if (empty($customers)) {
                    $stateManager->markFailed('customer', $customerId, $this->migrationId, 'Customer not found in Shopware DB');
                    $this->log('warning', 'Customer not found in Shopware DB', $customerId);

                    continue;
                }

                $customer = (object) $customers[0];

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
                } else {
                    $stateManager->markFailed('customer', $customer->id, $this->migrationId, 'WooCommerce returned no ID for customer');
                    $this->log('error', "WooCommerce returned no ID for customer '{$customer->email}'", $customer->id);
                }
            } catch (\Exception $e) {
                $stateManager->markFailed('customer', $customerId, $this->migrationId, $e->getMessage());
                $this->log('error', "Failed: {$e->getMessage()}", $customerId);
            }
        }
    }

    /**
     * Mark all customers in this batch as failed if the job itself exhausts its retries.
     */
    public function failed(Throwable $exception): void
    {
        $stateManager = app(StateManager::class);

        foreach ($this->customerIds as $customerId) {
            $stateManager->markFailed('customer', $customerId, $this->migrationId, $exception->getMessage());
        }

        MigrationLog::create([
            'migration_id' => $this->migrationId,
            'entity_type' => 'customer',
            'level' => 'error',
            'message' => 'Batch job failed after retries: '.$exception->getMessage(),
            'created_at' => now(),
        ]);
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

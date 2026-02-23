<?php

namespace App\Jobs;

use App\Models\MigrationLog;
use App\Models\MigrationRun;
use App\Services\ShopwareDB;
use App\Services\StateManager;
use App\Services\WooCommerceClient;
use App\Shopware\Readers\OrderReader;
use App\Shopware\Transformers\OrderTransformer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class MigrateOrdersJob implements ShouldQueue
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
        $reader = new OrderReader($db);
        $transformer = new OrderTransformer;

        $orders = $reader->fetchAll();

        foreach ($orders as $order) {
            if ($stateManager->alreadyMigrated('order', $order->id, $this->migrationId)) {
                continue;
            }

            try {
                $customer = $reader->fetchOrderCustomer($order->id);
                $billingAddress = ! empty($order->billing_address_id)
                    ? $reader->fetchAddress($order->billing_address_id)
                    : null;
                $shippingAddress = $reader->fetchShippingAddress($order->id);
                $lineItems = $reader->fetchLineItems($order->id);
                $trackingCodes = $reader->fetchDeliveryTracking($order->id);

                $data = $transformer->transform($order, $customer, $billingAddress, $shippingAddress, $lineItems, $trackingCodes);

                if (! empty($customer->customer_id)) {
                    $wooCustomerId = $stateManager->get('customer', $customer->customer_id, $this->migrationId);
                    if ($wooCustomerId) {
                        $data['customer_id'] = $wooCustomerId;
                    }
                }

                if ($migration->is_dry_run) {
                    $stateManager->markPending('order', $order->id, $this->migrationId, $data);
                    $this->log('info', "Dry run: order '{$order->order_number}'", $order->id);

                    continue;
                }

                $result = $woo->post('orders', $data);
                $wooId = $result['id'] ?? null;

                if ($wooId) {
                    $stateManager->set('order', $order->id, $wooId, $this->migrationId);
                    $this->log('info', "Migrated order '{$order->order_number}' → WC #{$wooId}", $order->id);
                }
            } catch (\Exception $e) {
                $stateManager->markFailed('order', $order->id, $this->migrationId, $e->getMessage());
                $this->log('error', "Failed: {$e->getMessage()}", $order->id);
            }
        }
    }

    protected function log(string $level, string $message, ?string $shopwareId = null): void
    {
        MigrationLog::create([
            'migration_id' => $this->migrationId,
            'entity_type' => 'order',
            'shopware_id' => $shopwareId,
            'level' => $level,
            'message' => $message,
            'created_at' => now(),
        ]);
    }
}

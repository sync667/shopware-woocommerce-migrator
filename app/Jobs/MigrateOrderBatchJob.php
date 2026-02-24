<?php

namespace App\Jobs;

use App\Models\MigrationLog;
use App\Models\MigrationRun;
use App\Services\ShopwareDB;
use App\Services\StateManager;
use App\Services\WooCommerceClient;
use App\Shopware\Readers\OrderReader;
use App\Shopware\Transformers\OrderTransformer;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class MigrateOrderBatchJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 5;

    public int $timeout = 600; // 10 minutes per batch

    public function __construct(
        protected int $migrationId,
        protected array $orderIds
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
        $reader = new OrderReader($db);
        $transformer = new OrderTransformer;

        try {
            foreach ($this->orderIds as $orderId) {
                if (app(\App\Services\CancellationService::class)->isCancelled($this->migrationId)) {
                    $this->batch()?->cancel();

                    return;
                }

                if ($stateManager->alreadyMigrated('order', $orderId, $this->migrationId)) {
                    continue;
                }

                try {
                    $orders = $db->select('
                    SELECT
                        LOWER(HEX(o.id)) AS id,
                        o.order_number,
                        o.currency_factor,
                        LOWER(HEX(o.currency_id)) AS currency_id,
                        LOWER(HEX(o.billing_address_id)) AS billing_address_id,
                        o.order_date_time,
                        o.amount_total,
                        o.amount_net,
                        o.position_price,
                        o.shipping_total,
                        o.created_at,
                        o.updated_at,
                        LOWER(HEX(sm.id)) AS state_id,
                        sm.technical_name AS order_state
                    FROM `order` o
                    LEFT JOIN state_machine_state sm ON sm.id = o.state_id
                    WHERE LOWER(HEX(o.id)) = ?
                      AND o.version_id = ?
                ', [$orderId, $db->liveVersionIdBin()]);

                    if (empty($orders)) {
                        $stateManager->markFailed('order', $orderId, $this->migrationId, 'Order not found in Shopware DB');
                        $this->log('warning', 'Order not found in Shopware DB', $orderId);

                        continue;
                    }

                    $order = $orders[0];

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
                        $stateManager->markSkipped('order', $order->id, $this->migrationId, $data);
                        $this->log('info', "Dry run: order '{$order->order_number}'", $order->id);

                        continue;
                    }

                    $result = $woo->post('orders', $data);
                    $wooId = $result['id'] ?? null;

                    if ($wooId) {
                        $stateManager->set('order', $order->id, $wooId, $this->migrationId);
                        $this->log('info', "Migrated order '{$order->order_number}' → WC #{$wooId}", $order->id);
                    }
                } catch (\Throwable $e) {
                    $stateManager->markFailed('order', $orderId, $this->migrationId, $e->getMessage());
                    $this->log('error', "Failed: {$e->getMessage()}", $orderId);
                }
            }
        } finally {
            $db->disconnect();
        }
    }

    /**
     * Mark all orders in this batch as failed if the job itself exhausts its retries.
     */
    public function failed(Throwable $exception): void
    {
        $stateManager = app(StateManager::class);

        foreach ($this->orderIds as $orderId) {
            $stateManager->markFailed('order', $orderId, $this->migrationId, $exception->getMessage());
        }

        MigrationLog::create([
            'migration_id' => $this->migrationId,
            'entity_type' => 'order',
            'level' => 'error',
            'message' => 'Batch job failed after retries: '.$exception->getMessage(),
            'created_at' => now(),
        ]);
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

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
            $creates = [];
            $createOrders = [];

            foreach ($this->orderIds as $orderId) {
                if (app(\App\Services\CancellationService::class)->isCancelled($this->migrationId)) {
                    $this->batch()?->cancel();

                    return;
                }

                if ($stateManager->alreadyMigrated('order', $orderId, $this->migrationId)) {
                    continue;
                }

                try {
                    $payload = $this->buildOrderPayload(
                        $orderId, $reader, $transformer, $stateManager, $migration
                    );

                    if ($payload === null) {
                        continue;
                    }

                    [$order, $data] = $payload;

                    if ($migration->is_dry_run) {
                        $stateManager->markSkipped('order', $order->id, $this->migrationId, $data);
                        $this->log('info', "Dry run: order '{$order->order_number}'", $order->id);

                        continue;
                    }

                    // Idempotency safety-net only matters on actual retries — a fresh
                    // first attempt already passed alreadyMigrated() above.
                    if ($this->attempts() > 1 || $migration->sync_mode === 'delta') {
                        $existing = $woo->findOrderByShopwareId($order->id, (string) ($order->order_number ?? ''));
                        if ($existing && ! empty($existing['id'])) {
                            $stateManager->set('order', $order->id, (int) $existing['id'], $this->migrationId);
                            $this->log('info', "Order '{$order->order_number}' already in WC as #{$existing['id']} (idempotent skip)", $order->id);

                            continue;
                        }
                    }

                    $creates[] = $data;
                    $createOrders[] = $order;
                } catch (\Throwable $e) {
                    $stateManager->markFailed('order', $orderId, $this->migrationId, $e->getMessage());
                    $this->log('error', "Failed: {$e->getMessage()}", $orderId);
                }
            }

            if ($creates === []) {
                return;
            }

            // WC's batch endpoint caps at 100 creates per request; our dispatcher
            // currently chunks at 50, but stay defensive in case that changes.
            foreach (array_chunk($creates, 100) as $chunkIdx => $chunkCreates) {
                $chunkOrders = array_slice($createOrders, $chunkIdx * 100, count($chunkCreates));
                $this->postBatchAndRecord($woo, $migration, $stateManager, $chunkCreates, $chunkOrders);
            }
        } finally {
            $db->disconnect();
        }
    }

    /**
     * Build the WC order payload for one Shopware order. Returns [order, data]
     * on success, null when the entity should be skipped (not found / pre-marked).
     *
     * @return array{0: object, 1: array<string, mixed>}|null
     */
    protected function buildOrderPayload(
        string $orderId,
        OrderReader $reader,
        OrderTransformer $transformer,
        StateManager $stateManager,
        MigrationRun $migration,
    ): ?array {
        $order = $reader->fetchOne($orderId);

        if ($order === null) {
            $stateManager->markFailed('order', $orderId, $this->migrationId, 'Order not found in Shopware DB');
            $this->log('warning', 'Order not found in Shopware DB', $orderId);

            return null;
        }

        $customer = $reader->fetchOrderCustomer($order->id);
        $billingAddress = ! empty($order->billing_address_id)
            ? $reader->fetchAddress($order->billing_address_id)
            : null;
        $shippingAddress = $reader->fetchShippingAddress($order->id);
        $lineItems = $reader->fetchLineItems($order->id);
        $trackingCodes = $reader->fetchDeliveryTracking($order->id);
        $shippingMethod = $reader->fetchShippingMethod($order->id);

        // Resolve WC product/variation IDs for each line item so WooCommerce
        // links order items to the migrated products properly. A Shopware
        // product UUID can belong to either a parent product or a variant —
        // check variation mapping first because a variation entity carries
        // the parent's woo_id in its payload (set by MigrateProductBatchJob).
        foreach ($lineItems as $lineItem) {
            if (empty($lineItem->product_id)) {
                continue;
            }

            $variationEntity = $stateManager->getEntity('variation', $lineItem->product_id, $this->migrationId);
            if ($variationEntity && $variationEntity->status === 'success' && $variationEntity->woo_id) {
                $lineItem->woo_variation_id = $variationEntity->woo_id;
                $parentWooId = $variationEntity->payload['parent_woo_id'] ?? null;
                if ($parentWooId === null) {
                    $this->log(
                        'warning',
                        "Variation {$lineItem->product_id} has no parent_woo_id payload; line item will lack product_id",
                        $orderId
                    );
                } else {
                    $lineItem->woo_product_id = $parentWooId;
                }

                continue;
            }

            $wooProductId = $stateManager->get('product', $lineItem->product_id, $this->migrationId);
            if ($wooProductId) {
                $lineItem->woo_product_id = $wooProductId;
            }
        }

        $data = $transformer->transform($order, $customer, $billingAddress, $shippingAddress, $lineItems, $trackingCodes, $shippingMethod);

        if (! empty($customer->customer_id)) {
            $wooCustomerId = $stateManager->get('customer', $customer->customer_id, $this->migrationId);
            if ($wooCustomerId) {
                $data['customer_id'] = $wooCustomerId;
            }
        }

        return [$order, $data];
    }

    /**
     * POST /orders/batch with the prepared `create[]` payload. The response's
     * `create[]` is documented to preserve input order, so we map each response
     * slot back to the originating Shopware order by index. Per-item errors
     * (WP_Error wrapped under `error` key) become per-entity markFailed rows —
     * one bad order in a batch doesn't fail the rest.
     *
     * @param  array<int, array<string, mixed>>  $chunkCreates
     * @param  array<int, object>  $chunkOrders
     */
    protected function postBatchAndRecord(
        WooCommerceClient $woo,
        MigrationRun $migration,
        StateManager $stateManager,
        array $chunkCreates,
        array $chunkOrders,
    ): void {
        try {
            $result = $woo->post('orders/batch', ['create' => array_values($chunkCreates)]);
        } catch (\Throwable $e) {
            // Whole-batch failure: mark every order in the chunk failed so the
            // operator sees the cause and Bus::batch retry logic can do its job.
            foreach ($chunkOrders as $order) {
                $stateManager->markFailed('order', $order->id, $this->migrationId, $e->getMessage());
                $this->log('error', "Batch POST failed: {$e->getMessage()}", $order->id);
            }
            throw $e;
        }

        $items = is_array($result['create'] ?? null) ? $result['create'] : [];

        foreach ($chunkOrders as $i => $order) {
            $item = $items[$i] ?? null;

            if (! is_array($item) || empty($item['id'])) {
                $errMessage = is_array($item['error'] ?? null)
                    ? ($item['error']['message'] ?? 'Unknown batch error')
                    : 'No id returned for order in batch response';
                $stateManager->markFailed('order', $order->id, $this->migrationId, $errMessage);
                $this->log('error', "Failed: {$errMessage}", $order->id);

                continue;
            }

            $wooId = $this->maybeRenumberOrder($migration, (int) $item['id'], $order);

            $stateManager->set('order', $order->id, $wooId, $this->migrationId);
            $this->log('info', "Migrated order '{$order->order_number}' → WC #{$wooId}", $order->id);
        }
    }

    /**
     * Renumber the just-created WC order to its Shopware order_number when
     * preserve_order_ids is on + WC DB configured + the number is purely
     * numeric. Returns the resulting WC id (renumbered or original auto id on
     * failure). Failures are logged as warnings — the migration continues.
     */
    protected function maybeRenumberOrder(\App\Models\MigrationRun $migration, int $autoId, object $order): int
    {
        $woo = $migration->woocommerceSettings();
        if (empty($woo['preserve_order_ids']) || ! is_numeric($order->order_number ?? null)) {
            return $autoId;
        }

        $targetId = (int) $order->order_number;
        if ($targetId === $autoId) {
            return $autoId;
        }

        $db = \App\Services\WooCommerceDB::fromMigration($migration);
        if (! $db->isConfigured()) {
            return $autoId;
        }

        try {
            $db->renumberOrder($autoId, $targetId);

            return $targetId;
        } catch (\Throwable $e) {
            $this->log('warning', "Renumber {$autoId}→{$targetId} skipped: {$e->getMessage()}", $order->id);

            return $autoId;
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

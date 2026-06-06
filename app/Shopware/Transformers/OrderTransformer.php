<?php

namespace App\Shopware\Transformers;

class OrderTransformer
{
    public const STATUS_MAP = [
        'open' => 'pending',
        'in_progress' => 'processing',
        'completed' => 'completed',
        'cancelled' => 'cancelled',
        'returned' => 'refunded',
        'failed' => 'failed',
        'reminded' => 'on-hold',
    ];

    public function transform(
        object $order,
        ?object $customer = null,
        ?object $billingAddress = null,
        ?object $shippingAddress = null,
        array $lineItems = [],
        array $trackingCodes = [],
        ?object $shippingMethod = null,
    ): array {
        $data = [
            'status' => self::STATUS_MAP[$order->status] ?? 'pending',
            'date_created' => isset($order->order_date) ? (new \DateTime($order->order_date))->format('Y-m-d\TH:i:s') : null,
            'set_paid' => in_array($order->status, ['completed', 'in_progress']),
            'billing' => $billingAddress ? $this->transformAddress($billingAddress) : [],
            'shipping' => $shippingAddress ? $this->transformAddress($shippingAddress) : [],
            'line_items' => $this->transformLineItems($lineItems),
            'meta_data' => [
                // `_order_number` drives the display via woocommerce_order_number filter.
                // `_shopware_order_number` stays separate as the idempotency key — admin
                // edits to the displayed number must not break re-import lookups.
                ['key' => '_order_number', 'value' => (string) ($order->order_number ?? '')],
                ['key' => '_shopware_order_number', 'value' => $order->order_number ?? ''],
                ['key' => '_shopware_order_id', 'value' => $order->id ?? ''],
            ],
        ];

        // Shipping lines — always include so the order total is correct in WC
        $shippingTotal = (float) ($order->shipping_total ?? 0);
        if ($shippingTotal > 0 || $shippingMethod !== null) {
            $data['shipping_lines'] = [[
                'method_id' => $shippingMethod ? ('shopware_'.($shippingMethod->method_id ?? 'other')) : 'other',
                'method_title' => $shippingMethod?->method_name ?? 'Shipping',
                'total' => $this->formatMoney($shippingTotal),
            ]];
        }

        if (! empty($order->affiliate_code)) {
            $data['meta_data'][] = ['key' => '_shopware_affiliate_code', 'value' => $order->affiliate_code];
        }

        if (! empty($order->campaign_code)) {
            $data['meta_data'][] = ['key' => '_shopware_campaign_code', 'value' => $order->campaign_code];
        }

        if (! empty($order->custom_fields)) {
            $customFields = is_string($order->custom_fields)
                ? json_decode($order->custom_fields, true)
                : (array) $order->custom_fields;
            if (is_array($customFields)) {
                foreach ($customFields as $key => $value) {
                    if ($value !== null && $value !== '' && $value !== []) {
                        $data['meta_data'][] = ['key' => '_sw_cf_'.$key, 'value' => $value];
                    }
                }
            }
        }

        if ($customer) {
            $email = $customer->email ?? '';
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $email = 'order-'.($order->order_number ?? $order->id).'@migrated.invalid';
            }
            $data['billing']['email'] = $email;
            $data['billing']['first_name'] = $data['billing']['first_name'] ?: ($customer->first_name ?? '');
            $data['billing']['last_name'] = $data['billing']['last_name'] ?: ($customer->last_name ?? '');
        }

        if (! empty($order->customer_comment)) {
            $data['customer_note'] = $order->customer_comment;
        }

        // WooCommerce Shipment Tracking expects a single meta entry whose value is
        // an array of all tracking items. Emitting one meta per code only kept the
        // last one (post-meta with the same key gets de-duplicated by WordPress).
        if (! empty($trackingCodes)) {
            $items = [];
            foreach ($trackingCodes as $trackingCode) {
                $items[] = [
                    'tracking_number' => $trackingCode,
                    'tracking_provider' => 'Custom Provider',
                    'date_shipped' => $order->order_date ?? '',
                ];
            }
            $data['meta_data'][] = [
                'key' => '_wc_shipment_tracking_items',
                'value' => $items,
            ];
        }

        return $data;
    }

    protected function transformAddress(object $address): array
    {
        $stateCode = $address->state_code ?? '';
        if ($stateCode && str_contains($stateCode, '-')) {
            $stateCode = substr($stateCode, strpos($stateCode, '-') + 1);
        }

        // Concatenate both additional address lines into WC's single address_2 slot.
        $extraLines = array_filter([
            $address->additional_address_line1 ?? null,
            $address->additional_address_line2 ?? null,
        ], static fn ($v) => is_string($v) && trim($v) !== '');
        $address2 = implode("\n", $extraLines);

        $data = [
            'first_name' => $address->first_name ?? '',
            'last_name' => $address->last_name ?? '',
            'company' => $address->company ?? '',
            'address_1' => $address->street ?? '',
            'address_2' => $address2,
            'city' => $address->city ?? '',
            'state' => $stateCode,
            'postcode' => $address->zipcode ?? '',
            'country' => $address->country_iso ?? '',
            'phone' => $address->phone ?? '',
        ];

        if ($address->vat_id ?? '') {
            $data['vat_id'] = $address->vat_id;
        }

        return $data;
    }

    protected function transformLineItems(array $lineItems): array
    {
        $items = [];
        foreach ($lineItems as $item) {
            if (($item->type ?? '') !== 'product') {
                continue;
            }

            $payload = is_string($item->payload ?? null) ? json_decode($item->payload, true) : ($item->payload ?? []);

            $lineItem = [
                'name' => $item->name ?? '',
                'quantity' => (int) ($item->quantity ?? 1),
                'subtotal' => $this->formatMoney((float) ($item->unit_price ?? 0) * (int) ($item->quantity ?? 1)),
                'total' => $this->formatMoney((float) ($item->total_price ?? 0)),
                'sku' => $payload['productNumber'] ?? '',
            ];

            // Link to WC product when available (resolved by the job via StateManager)
            if (! empty($item->woo_product_id)) {
                $lineItem['product_id'] = $item->woo_product_id;
            }

            // Link to WC variation when available
            if (! empty($item->woo_variation_id)) {
                $lineItem['variation_id'] = $item->woo_variation_id;
            }

            $items[] = $lineItem;
        }

        return $items;
    }

    /**
     * Format a money value as a fixed 2-decimal string with no thousands separator.
     * See ProductTransformer::formatMoney() for the rationale.
     */
    protected function formatMoney(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}

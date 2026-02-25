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
                ['key' => '_shopware_order_number', 'value' => $order->order_number ?? ''],
                ['key' => '_shopware_order_id', 'value' => $order->id ?? ''],
            ],
        ];

        // Shipping lines — always include so the order total is correct in WC
        $shippingTotal = round((float) ($order->shipping_total ?? 0), 2);
        if ($shippingTotal > 0 || $shippingMethod !== null) {
            $data['shipping_lines'] = [[
                'method_id' => $shippingMethod ? ('shopware_'.($shippingMethod->method_id ?? 'other')) : 'other',
                'method_title' => $shippingMethod?->method_name ?? 'Shipping',
                'total' => (string) $shippingTotal,
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
            $data['billing']['email'] = $customer->email ?? '';
            $data['billing']['first_name'] = $data['billing']['first_name'] ?: ($customer->first_name ?? '');
            $data['billing']['last_name'] = $data['billing']['last_name'] ?: ($customer->last_name ?? '');
        }

        if (! empty($order->customer_comment)) {
            $data['customer_note'] = $order->customer_comment;
        }

        // Add tracking numbers if available
        if (! empty($trackingCodes)) {
            foreach ($trackingCodes as $index => $trackingCode) {
                $data['meta_data'][] = [
                    'key' => '_wc_shipment_tracking_items',
                    'value' => [[
                        'tracking_number' => $trackingCode,
                        'tracking_provider' => 'Custom Provider',
                        'date_shipped' => $order->order_date ?? '',
                    ]],
                ];
            }
        }

        return $data;
    }

    protected function transformAddress(object $address): array
    {
        $stateCode = $address->state_code ?? '';
        if ($stateCode && str_contains($stateCode, '-')) {
            $stateCode = substr($stateCode, strpos($stateCode, '-') + 1);
        }

        return [
            'first_name' => $address->first_name ?? '',
            'last_name' => $address->last_name ?? '',
            'company' => $address->company ?? '',
            'address_1' => $address->street ?? '',
            'address_2' => $address->address_2 ?? '',
            'city' => $address->city ?? '',
            'state' => $stateCode,
            'postcode' => $address->zipcode ?? '',
            'country' => $address->country_iso ?? '',
            'phone' => $address->phone ?? '',
        ];
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
                'subtotal' => (string) round((float) ($item->unit_price ?? 0) * (int) ($item->quantity ?? 1), 2),
                'total' => (string) round((float) ($item->total_price ?? 0), 2),
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
}

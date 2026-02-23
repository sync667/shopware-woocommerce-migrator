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
    ): array {
        $data = [
            'status' => self::STATUS_MAP[$order->status] ?? 'pending',
            'date_created' => $order->order_date ?? null,
            'set_paid' => in_array($order->status, ['completed', 'in_progress']),
            'billing' => $billingAddress ? $this->transformAddress($billingAddress) : [],
            'shipping' => $shippingAddress ? $this->transformAddress($shippingAddress) : [],
            'line_items' => $this->transformLineItems($lineItems),
            'meta_data' => [
                ['key' => '_shopware_order_number', 'value' => $order->order_number ?? ''],
                ['key' => '_shopware_order_id', 'value' => $order->id ?? ''],
            ],
        ];

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

            $items[] = [
                'name' => $item->name ?? '',
                'quantity' => (int) ($item->quantity ?? 1),
                'subtotal' => (string) round((float) ($item->unit_price ?? 0) * (int) ($item->quantity ?? 1), 2),
                'total' => (string) round((float) ($item->total_price ?? 0), 2),
                'sku' => $payload['productNumber'] ?? '',
            ];
        }

        return $items;
    }
}

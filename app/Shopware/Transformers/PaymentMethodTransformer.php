<?php

namespace App\Shopware\Transformers;

class PaymentMethodTransformer
{
    public function transform(object $paymentMethod): array
    {
        return [
            'id' => 'shopware_'.($paymentMethod->id ?? ''),
            'title' => $paymentMethod->name ?: 'Unnamed Payment Method',
            'description' => $paymentMethod->description ?? '',
            'enabled' => (bool) ($paymentMethod->active ?? false),
            'method_title' => $paymentMethod->name ?: 'Unnamed Payment Method',
            'method_description' => $this->getMethodDescription($paymentMethod->handler_identifier ?? ''),
            'settings' => [
                'enabled' => (bool) ($paymentMethod->active ?? false),
            ],
            'meta_data' => [
                ['key' => '_shopware_id', 'value' => $paymentMethod->id ?? ''],
                ['key' => '_shopware_handler', 'value' => $paymentMethod->handler_identifier ?? ''],
                ['key' => '_shopware_position', 'value' => $paymentMethod->position ?? 0],
                ['key' => '_after_order_enabled', 'value' => (bool) ($paymentMethod->after_order_enabled ?? false)],
            ],
        ];
    }

    protected function getMethodDescription(string $handlerIdentifier): string
    {
        // Extract payment type from handler identifier
        if (str_contains($handlerIdentifier, 'PayPal')) {
            return 'PayPal payment gateway';
        }

        if (str_contains($handlerIdentifier, 'Invoice')) {
            return 'Invoice payment method';
        }

        if (str_contains($handlerIdentifier, 'Prepayment')) {
            return 'Prepayment / Bank transfer';
        }

        if (str_contains($handlerIdentifier, 'CashOnDelivery')) {
            return 'Cash on delivery';
        }

        if (str_contains($handlerIdentifier, 'DefaultPayment')) {
            return 'Default payment method';
        }

        return 'Migrated from Shopware';
    }
}

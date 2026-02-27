<?php

namespace App\Shopware\Transformers;

class PaymentMethodTransformer
{
    public function transform(object $paymentMethod): array
    {
        $technicalName = $paymentMethod->technical_name ?? null;

        return [
            'id' => 'shopware_'.($paymentMethod->id ?? ''),
            'title' => $paymentMethod->name ?: 'Unnamed Payment Method',
            'description' => $paymentMethod->description ?? '',
            'enabled' => (bool) ($paymentMethod->active ?? false),
            'method_title' => $paymentMethod->name ?: 'Unnamed Payment Method',
            'method_description' => $this->getMethodDescription(
                $paymentMethod->handler_identifier ?? '',
                $technicalName
            ),
            'settings' => [
                'enabled' => (bool) ($paymentMethod->active ?? false),
            ],
            'meta_data' => array_filter([
                ['key' => '_shopware_id', 'value' => $paymentMethod->id ?? ''],
                ['key' => '_shopware_handler', 'value' => $paymentMethod->handler_identifier ?? ''],
                $technicalName ? ['key' => '_shopware_technical_name', 'value' => $technicalName] : null,
                ['key' => '_shopware_position', 'value' => $paymentMethod->position ?? 0],
                ['key' => '_after_order_enabled', 'value' => (bool) ($paymentMethod->after_order_enabled ?? false)],
            ]),
        ];
    }

    protected function getMethodDescription(string $handlerIdentifier, ?string $technicalName = null): string
    {
        // Prefer technical_name for identification (available in Shopware 6.6+)
        if ($technicalName !== null) {
            if (str_contains($technicalName, 'paypal') || str_contains($technicalName, 'PayPal')) {
                return 'PayPal payment gateway';
            }
            if (str_contains($technicalName, 'invoice') || str_contains($technicalName, 'Invoice')) {
                return 'Invoice payment method';
            }
            if (str_contains($technicalName, 'prepayment') || str_contains($technicalName, 'Prepayment')) {
                return 'Prepayment / Bank transfer';
            }
            if (str_contains($technicalName, 'cash') || str_contains($technicalName, 'CashOnDelivery')) {
                return 'Cash on delivery';
            }
            if (str_contains($technicalName, 'default') || str_contains($technicalName, 'DefaultPayment')) {
                return 'Default payment method';
            }
        }

        // Fall back to handler_identifier (works on all versions)
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

<?php

namespace App\Shopware\Transformers;

class CustomerTransformer
{
    public function transform(object $customer, ?object $billingAddress = null, ?object $shippingAddress = null): array
    {
        $data = [
            'email' => $customer->email,
            'first_name' => $customer->first_name ?? '',
            'last_name' => $customer->last_name ?? '',
            'role' => 'customer',
            'meta_data' => [],
        ];

        if ($customer->customer_number ?? '') {
            $data['meta_data'][] = ['key' => '_shopware_customer_number', 'value' => $customer->customer_number];
        }

        if (isset($customer->guest)) {
            $data['meta_data'][] = ['key' => '_is_guest', 'value' => (bool) $customer->guest];
        }

        if (isset($customer->newsletter)) {
            $data['meta_data'][] = ['key' => '_newsletter_subscribed', 'value' => (bool) $customer->newsletter];
        }

        // Preserve Shopware bcrypt password hash for optional custom authentication
        if ($customer->password ?? '') {
            $data['meta_data'][] = ['key' => '_shopware_password_hash', 'value' => $customer->password];
        }

        if ($billingAddress) {
            $data['billing'] = $this->transformAddress($billingAddress);
        } elseif ($customer->company ?? '') {
            $data['billing'] = ['company' => $customer->company];
        }

        if ($shippingAddress) {
            $data['shipping'] = $this->transformAddress($shippingAddress);
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
}

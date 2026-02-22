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
        ];

        if ($billingAddress) {
            $data['billing'] = $this->transformAddress($billingAddress);
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

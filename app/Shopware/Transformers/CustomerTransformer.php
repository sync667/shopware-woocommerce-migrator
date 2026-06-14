<?php

namespace App\Shopware\Transformers;

class CustomerTransformer
{
    public function transform(
        object $customer,
        ?object $billingAddress = null,
        ?object $shippingAddress = null,
        ?string $newPassword = null,
    ): array {
        $data = [
            'email' => self::sanitizeEmail($customer->email ?? ''),
            'first_name' => $customer->first_name ?? '',
            'last_name' => $customer->last_name ?? '',
            'role' => 'customer',
            'meta_data' => [],
        ];

        // Explicit password on create so WC doesn't auto-generate one (which would also
        // trigger the new-account email even with email notifications disabled at the
        // setting level for some plugins). The customer never knows this value —
        // _requires_password_reset below tells the storefront to force a reset.
        if ($newPassword !== null && $newPassword !== '') {
            $data['password'] = $newPassword;
            $data['meta_data'][] = ['key' => '_requires_password_reset', 'value' => '1'];
        }

        if ($customer->id ?? '') {
            $data['meta_data'][] = ['key' => '_shopware_customer_id', 'value' => $customer->id];
        }

        if ($customer->customer_number ?? '') {
            $data['meta_data'][] = ['key' => '_shopware_customer_number', 'value' => $customer->customer_number];
        }

        if (isset($customer->guest)) {
            $data['meta_data'][] = ['key' => '_is_guest', 'value' => (bool) $customer->guest];
        }

        if (isset($customer->newsletter)) {
            $data['meta_data'][] = ['key' => '_newsletter_subscribed', 'value' => (bool) $customer->newsletter];
        }

        if ($customer->password ?? '') {
            $data['meta_data'][] = ['key' => '_shopware_password_hash', 'value' => $customer->password];
        }

        // Pre-Shopware-6 imports keep their original password under legacy_password +
        // legacy_encoder (e.g. md5+salt from SW5). Preserve both so a custom auth plugin
        // can verify against the original encoder before falling through to the bcrypt.
        if ($customer->legacy_password ?? '') {
            $data['meta_data'][] = ['key' => '_shopware_legacy_password', 'value' => $customer->legacy_password];
            if ($customer->legacy_encoder ?? '') {
                $data['meta_data'][] = ['key' => '_shopware_legacy_encoder', 'value' => $customer->legacy_encoder];
            }
        }

        if ($customer->title ?? '') {
            $data['meta_data'][] = ['key' => '_billing_title', 'value' => $customer->title];
        }

        if (! empty($customer->birthday)) {
            $data['meta_data'][] = ['key' => '_birthday', 'value' => (string) $customer->birthday];
        }

        // vat_ids is a JSON array (Shopware allows multiple VAT numbers per customer).
        // Most B2B integrations care about the first one — store all for completeness.
        $vatIds = $this->parseVatIds($customer->vat_ids ?? null);
        if ($vatIds !== []) {
            $data['meta_data'][] = ['key' => '_billing_vat', 'value' => $vatIds[0]];
            if (count($vatIds) > 1) {
                $data['meta_data'][] = ['key' => '_shopware_vat_ids', 'value' => json_encode($vatIds)];
            }
        }

        if ($billingAddress) {
            $data['billing'] = $this->transformAddress($billingAddress, $customer);
        } elseif ($customer->company ?? '') {
            $data['billing'] = ['company' => $customer->company];
        }

        if ($shippingAddress) {
            $data['shipping'] = $this->transformAddress($shippingAddress, $customer);
        }

        return $data;
    }

    public static function sanitizeEmail(string $email): string
    {
        $email = trim($email);
        $email = trim($email, "\"'");

        if (preg_match('/<([^>]+)>/', $email, $m)) {
            $email = trim($m[1]);
        }

        return $email;
    }

    /**
     * @return array<int, string>
     */
    private function parseVatIds(mixed $raw): array
    {
        if (! is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn ($v) => is_string($v) ? trim($v) : '', $decoded),
            static fn (string $v) => $v !== '',
        ));
    }

    protected function transformAddress(object $address, ?object $customer = null): array
    {
        $stateCode = $address->state_code ?? '';
        if ($stateCode && str_contains($stateCode, '-')) {
            $stateCode = substr($stateCode, strpos($stateCode, '-') + 1);
        }

        // Shopware stores up to two `additional_address_line*` fields. WC only has one
        // `address_2` slot — concatenate so neither half is lost (real prod data has
        // ~2,500 order addresses with line2 populated).
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
}

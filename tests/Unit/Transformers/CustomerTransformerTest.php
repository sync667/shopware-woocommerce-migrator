<?php

namespace Tests\Unit\Transformers;

use App\Shopware\Transformers\CustomerTransformer;
use PHPUnit\Framework\TestCase;

class CustomerTransformerTest extends TestCase
{
    public function test_transforms_customer(): void
    {
        $transformer = new CustomerTransformer;

        $customer = (object) [
            'email' => 'jan@example.pl',
            'first_name' => 'Jan',
            'last_name' => 'Kowalski',
        ];

        $billing = (object) [
            'first_name' => 'Jan',
            'last_name' => 'Kowalski',
            'street' => 'ul. Marszałkowska 1',
            'zipcode' => '00-001',
            'city' => 'Warszawa',
            'company' => '',
            'address_2' => '',
            'phone' => '+48123456789',
            'country_iso' => 'PL',
            'state_code' => 'PL-MZ',
        ];

        $result = $transformer->transform($customer, $billing);

        $this->assertEquals('jan@example.pl', $result['email']);
        $this->assertEquals('customer', $result['role']);
        $this->assertEquals('Warszawa', $result['billing']['city']);
        $this->assertEquals('MZ', $result['billing']['state']);
        $this->assertEquals('PL', $result['billing']['country']);
    }

    public function test_password_is_set_when_provided(): void
    {
        $transformer = new CustomerTransformer;
        $customer = (object) ['email' => 'a@b.test', 'first_name' => 'A', 'last_name' => 'B'];

        $result = $transformer->transform($customer, newPassword: 'random-plain-text-32');

        $this->assertSame('random-plain-text-32', $result['password']);
        $hasResetFlag = collect($result['meta_data'])->contains(
            fn ($m) => $m['key'] === '_requires_password_reset' && $m['value'] === '1'
        );
        $this->assertTrue($hasResetFlag, 'Customers with a transformer-set password must be flagged for reset');
    }

    public function test_no_password_field_when_omitted(): void
    {
        $transformer = new CustomerTransformer;
        $customer = (object) ['email' => 'a@b.test', 'first_name' => 'A', 'last_name' => 'B'];

        $result = $transformer->transform($customer);

        $this->assertArrayNotHasKey('password', $result);
    }

    public function test_preserves_shopware_password_hash_when_present(): void
    {
        $transformer = new CustomerTransformer;
        $customer = (object) [
            'email' => 'a@b.test',
            'first_name' => 'A',
            'last_name' => 'B',
            'password' => '$2y$10$abcdefghijklmnopqrstuvwxyzABCDEFGH',
        ];

        $result = $transformer->transform($customer);

        $hash = collect($result['meta_data'])->firstWhere('key', '_shopware_password_hash');
        $this->assertNotNull($hash);
        $this->assertSame('$2y$10$abcdefghijklmnopqrstuvwxyzABCDEFGH', $hash['value']);
    }
}

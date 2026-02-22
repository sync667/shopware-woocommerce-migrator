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
}

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

    public function test_preserves_legacy_password_and_encoder(): void
    {
        $transformer = new CustomerTransformer;
        $customer = (object) [
            'email' => 'a@b.test',
            'first_name' => 'A',
            'last_name' => 'B',
            'legacy_password' => 'abc123def',
            'legacy_encoder' => 'Md5',
        ];

        $result = $transformer->transform($customer);
        $metas = collect($result['meta_data']);

        $this->assertSame('abc123def', $metas->firstWhere('key', '_shopware_legacy_password')['value']);
        $this->assertSame('Md5', $metas->firstWhere('key', '_shopware_legacy_encoder')['value']);
    }

    public function test_vat_ids_first_value_becomes_billing_vat(): void
    {
        // Real production shape: customer.vat_ids is a JSON array of strings.
        $transformer = new CustomerTransformer;
        $customer = (object) [
            'email' => 'b@b.test',
            'first_name' => 'B',
            'last_name' => 'B',
            'vat_ids' => '["PL6731784893","DE123456789"]',
        ];

        $result = $transformer->transform($customer);
        $metas = collect($result['meta_data']);

        $this->assertSame('PL6731784893', $metas->firstWhere('key', '_billing_vat')['value']);
        $this->assertSame('["PL6731784893","DE123456789"]', $metas->firstWhere('key', '_shopware_vat_ids')['value']);
    }

    public function test_vat_ids_handles_malformed_json(): void
    {
        $transformer = new CustomerTransformer;
        $customer = (object) [
            'email' => 'c@b.test',
            'first_name' => 'C',
            'last_name' => 'D',
            'vat_ids' => 'not-json',
        ];

        $result = $transformer->transform($customer);
        $hasVat = collect($result['meta_data'])->contains(fn ($m) => $m['key'] === '_billing_vat');

        $this->assertFalse($hasVat);
    }

    public function test_address_concatenates_both_additional_lines(): void
    {
        $transformer = new CustomerTransformer;
        $customer = (object) [
            'email' => 'd@b.test',
            'first_name' => 'D',
            'last_name' => 'E',
        ];

        $billing = (object) [
            'first_name' => 'D',
            'last_name' => 'E',
            'street' => 'ul. Marszałkowska 1',
            'zipcode' => '00-001',
            'city' => 'Warszawa',
            'company' => '',
            'additional_address_line1' => 'apt. 5B',
            'additional_address_line2' => 'klatka 2',
            'phone' => '+48123456789',
            'country_iso' => 'PL',
            'state_code' => 'PL-MZ',
        ];

        $result = $transformer->transform($customer, $billing);

        $this->assertSame("apt. 5B\nklatka 2", $result['billing']['address_2']);
    }

    public function test_address_includes_vat_id_when_set(): void
    {
        $transformer = new CustomerTransformer;
        $customer = (object) ['email' => 'e@b.test', 'first_name' => 'E', 'last_name' => 'F'];

        $billing = (object) [
            'first_name' => 'E',
            'last_name' => 'F',
            'street' => 'Foo 1',
            'zipcode' => '00-001',
            'city' => 'Warszawa',
            'vat_id' => 'PL5213969319',
            'country_iso' => 'PL',
            'state_code' => '',
        ];

        $result = $transformer->transform($customer, $billing);

        $this->assertSame('PL5213969319', $result['billing']['vat_id']);
    }

    /**
     * @return iterable<string, array{0:string,1:string}>
     */
    public static function emailSanitizationCases(): iterable
    {
        yield 'clean' => ['user@example.com', 'user@example.com'];
        yield 'trim whitespace' => ["  user@example.com\n", 'user@example.com'];
        yield 'strip wrapping single quotes' => ["'ospmszczonow@wp.pl'", 'ospmszczonow@wp.pl'];
        yield 'strip wrapping double quotes' => ['"user@example.com"', 'user@example.com'];
        yield 'rfc-822 display name' => ['Krzysztof Kokoszka <kkokoszka@supon.rzeszow.pl>', 'kkokoszka@supon.rzeszow.pl'];
        yield 'display name with quotes' => ['"Foo Bar" <foo@bar.com>', 'foo@bar.com'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('emailSanitizationCases')]
    public function test_email_sanitization(string $input, string $expected): void
    {
        $this->assertSame($expected, \App\Shopware\Transformers\CustomerTransformer::sanitizeEmail($input));
    }

    public function test_customer_email_runs_through_sanitizer(): void
    {
        $transformer = new \App\Shopware\Transformers\CustomerTransformer;
        $customer = (object) [
            'id' => 'cust1',
            'email' => 'Krzysztof Kokoszka <kkokoszka@supon.rzeszow.pl>',
        ];

        $result = $transformer->transform($customer);

        $this->assertSame('kkokoszka@supon.rzeszow.pl', $result['email']);
    }
}

<?php

namespace App\Shopware\Readers;

use App\Services\ShopwareDB;

class CustomerReader
{
    public function __construct(protected ShopwareDB $db) {}

    public function fetchAll(): array
    {
        return $this->db->select('
            SELECT
                LOWER(HEX(c.id)) AS id,
                c.first_name,
                c.last_name,
                c.email,
                c.password,
                c.active,
                c.customer_number,
                c.company,
                c.guest,
                c.newsletter,
                LOWER(HEX(c.default_billing_address_id)) AS billing_address_id,
                LOWER(HEX(c.default_shipping_address_id)) AS shipping_address_id
            FROM customer c
            ORDER BY c.email ASC
        ');
    }

    public function fetchAddress(string $addressId): ?object
    {
        $results = $this->db->select("
            SELECT
                LOWER(HEX(ca.id)) AS id,
                ca.first_name,
                ca.last_name,
                ca.street,
                ca.zipcode,
                ca.city,
                ca.company,
                ca.additional_address_line1 AS address_2,
                ca.phone_number AS phone,
                COALESCE(co.iso, '') AS country_iso,
                COALESCE(cs.short_code, '') AS state_code
            FROM customer_address ca
            LEFT JOIN country co ON co.id = ca.country_id
            LEFT JOIN country_state cs ON cs.id = ca.country_state_id
            WHERE ca.id = UNHEX(?)
        ", [$addressId]);

        return $results[0] ?? null;
    }
}

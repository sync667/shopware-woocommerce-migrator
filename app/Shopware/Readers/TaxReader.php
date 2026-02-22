<?php

namespace App\Shopware\Readers;

use App\Services\ShopwareDB;

class TaxReader
{
    public function __construct(protected ShopwareDB $db) {}

    public function fetchAll(): array
    {
        return $this->db->select('
            SELECT
                LOWER(HEX(t.id)) AS id,
                t.tax_rate,
                t.name
            FROM tax t
            ORDER BY t.name ASC
        ');
    }

    public function fetchRules(string $taxId): array
    {
        return $this->db->select("
            SELECT
                LOWER(HEX(tr.id)) AS id,
                tr.tax_rate AS rate,
                LOWER(HEX(tr.country_id)) AS country_id,
                COALESCE(c.iso, '') AS country_iso,
                COALESCE(trt.type_technical_name, '') AS rule_type
            FROM tax_rule tr
            LEFT JOIN tax_rule_type trt ON trt.id = tr.tax_rule_type_id
            LEFT JOIN country c ON c.id = tr.country_id
            WHERE tr.tax_id = UNHEX(?)
            ORDER BY c.iso ASC
        ", [$taxId]);
    }
}

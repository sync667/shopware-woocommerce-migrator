<?php

namespace App\Shopware\Readers;

use App\Services\ShopwareDB;

class ShippingMethodReader
{
    public function __construct(protected ShopwareDB $db) {}

    public function fetchAll(): array
    {
        return $this->db->select("
            SELECT
                LOWER(HEX(sm.id)) AS id,
                COALESCE(smt.name, '') AS name,
                COALESCE(smt.description, '') AS description,
                sm.active,
                sm.position,
                LOWER(HEX(sm.tax_id)) AS tax_id
            FROM shipping_method sm
            LEFT JOIN shipping_method_translation smt
                ON smt.shipping_method_id = sm.id
                AND smt.language_id = ?
            WHERE sm.active = 1
            ORDER BY sm.position ASC, smt.name ASC
        ", [$this->db->languageIdBin()]);
    }

    public function fetchPrices(string $shippingMethodId): array
    {
        return $this->db->select('
            SELECT
                LOWER(HEX(smp.id)) AS id,
                smp.currency_price,
                smp.quantity_start,
                smp.quantity_end,
                smp.calculation
            FROM shipping_method_price smp
            WHERE smp.shipping_method_id = UNHEX(?)
            ORDER BY smp.quantity_start ASC
        ', [$shippingMethodId]);
    }

    public function fetchUpdatedSince(\DateTimeInterface $since): array
    {
        return $this->db->select("
            SELECT
                LOWER(HEX(sm.id)) AS id,
                COALESCE(smt.name, '') AS name,
                COALESCE(smt.description, '') AS description,
                sm.active,
                sm.position,
                LOWER(HEX(sm.tax_id)) AS tax_id,
                sm.updated_at,
                sm.created_at
            FROM shipping_method sm
            LEFT JOIN shipping_method_translation smt
                ON smt.shipping_method_id = sm.id
                AND smt.language_id = ?
            WHERE (sm.updated_at > ? OR sm.created_at > ?)
            ORDER BY sm.updated_at ASC, sm.created_at ASC
        ", [
            $this->db->languageIdBin(),
            $since->format('Y-m-d H:i:s'),
            $since->format('Y-m-d H:i:s'),
        ]);
    }
}

<?php

namespace App\Shopware\Readers;

use App\Services\ShopwareDB;

class PaymentMethodReader
{
    public function __construct(protected ShopwareDB $db) {}

    public function fetchAll(): array
    {
        return $this->db->select("
            SELECT
                LOWER(HEX(pm.id)) AS id,
                COALESCE(pmt.name, '') AS name,
                COALESCE(pmt.description, '') AS description,
                pm.handler_identifier,
                pm.active,
                pm.position,
                pm.after_order_enabled
            FROM payment_method pm
            LEFT JOIN payment_method_translation pmt
                ON pmt.payment_method_id = pm.id
                AND pmt.language_id = ?
            WHERE pm.active = 1
            ORDER BY pm.position ASC, pmt.name ASC
        ", [$this->db->languageIdBin()]);
    }

    public function fetchUpdatedSince(\DateTimeInterface $since): array
    {
        return $this->db->select("
            SELECT
                LOWER(HEX(pm.id)) AS id,
                COALESCE(pmt.name, '') AS name,
                COALESCE(pmt.description, '') AS description,
                pm.handler_identifier,
                pm.active,
                pm.position,
                pm.after_order_enabled,
                pm.updated_at,
                pm.created_at
            FROM payment_method pm
            LEFT JOIN payment_method_translation pmt
                ON pmt.payment_method_id = pm.id
                AND pmt.language_id = ?
            WHERE (pm.updated_at > ? OR pm.created_at > ?)
            ORDER BY pm.updated_at ASC, pm.created_at ASC
        ", [
            $this->db->languageIdBin(),
            $since->format('Y-m-d H:i:s'),
            $since->format('Y-m-d H:i:s'),
        ]);
    }
}

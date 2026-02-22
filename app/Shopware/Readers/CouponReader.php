<?php

namespace App\Shopware\Readers;

use App\Services\ShopwareDB;

class CouponReader
{
    public function __construct(protected ShopwareDB $db) {}

    public function fetchAll(): array
    {
        return $this->db->select("
            SELECT
                LOWER(HEX(p.id)) AS id,
                COALESCE(pt.name, p.name) AS name,
                p.active,
                p.valid_from,
                p.valid_until,
                p.max_redemptions_global AS usage_limit,
                p.max_redemptions_per_customer AS usage_limit_per_user,
                p.code,
                p.use_individual_codes
            FROM promotion p
            LEFT JOIN promotion_translation pt
                ON pt.promotion_id = p.id
                AND pt.language_id = ?
            ORDER BY p.name ASC
        ", [$this->db->languageIdBin()]);
    }

    public function fetchDiscounts(string $promotionId): array
    {
        return $this->db->select("
            SELECT
                LOWER(HEX(pd.id)) AS id,
                pd.type AS discount_type,
                pd.value AS discount_value,
                pd.scope
            FROM promotion_discount pd
            WHERE pd.promotion_id = UNHEX(?)
        ", [$promotionId]);
    }

    public function fetchIndividualCodes(string $promotionId): array
    {
        return $this->db->select("
            SELECT
                LOWER(HEX(pic.id)) AS id,
                pic.code
            FROM promotion_individual_code pic
            WHERE pic.promotion_id = UNHEX(?)
        ", [$promotionId]);
    }
}

<?php

namespace App\Shopware\Readers;

use App\Services\ShopwareDB;

class ReviewReader
{
    public function __construct(protected ShopwareDB $db) {}

    public function fetchAll(): array
    {
        return $this->db->select("
            SELECT
                LOWER(HEX(pr.id)) AS id,
                LOWER(HEX(pr.product_id)) AS product_id,
                LOWER(HEX(pr.customer_id)) AS customer_id,
                pr.points AS rating,
                pr.status AS active,
                pr.comment,
                pr.title,
                pr.created_at,
                COALESCE(c.first_name, '') AS author_first_name,
                COALESCE(c.last_name, '') AS author_last_name,
                COALESCE(c.email, '') AS author_email
            FROM product_review pr
            LEFT JOIN customer c ON c.id = pr.customer_id
            WHERE pr.product_version_id = ?
            ORDER BY pr.created_at ASC
        ", [$this->db->liveVersionIdBin()]);
    }
}

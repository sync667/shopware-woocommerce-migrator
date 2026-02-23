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
                COALESCE(pr.content, pr.comment, '') AS comment,
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

    public function fetchUpdatedSince(\DateTimeInterface $since): array
    {
        return $this->db->select("
            SELECT
                LOWER(HEX(pr.id)) AS id,
                LOWER(HEX(pr.product_id)) AS product_id,
                LOWER(HEX(pr.customer_id)) AS customer_id,
                pr.points AS rating,
                pr.status AS active,
                COALESCE(pr.content, pr.comment, '') AS comment,
                pr.title,
                pr.created_at,
                pr.updated_at,
                COALESCE(c.first_name, '') AS author_first_name,
                COALESCE(c.last_name, '') AS author_last_name,
                COALESCE(c.email, '') AS author_email
            FROM product_review pr
            LEFT JOIN customer c ON c.id = pr.customer_id
            WHERE pr.product_version_id = ?
              AND (pr.updated_at > ? OR pr.created_at > ?)
            ORDER BY pr.updated_at ASC, pr.created_at ASC
        ", [
            $this->db->liveVersionIdBin(),
            $since->format('Y-m-d H:i:s'),
            $since->format('Y-m-d H:i:s'),
        ]);
    }
}

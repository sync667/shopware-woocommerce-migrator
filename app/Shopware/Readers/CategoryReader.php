<?php

namespace App\Shopware\Readers;

use App\Services\ShopwareDB;

class CategoryReader
{
    public function __construct(protected ShopwareDB $db) {}

    public function fetchAll(): array
    {
        return $this->db->select("
            SELECT
                LOWER(HEX(c.id)) AS id,
                LOWER(HEX(c.parent_id)) AS parent_id,
                COALESCE(ct.name, '') AS name,
                COALESCE(ct.description, '') AS description,
                c.auto_increment AS sort_order,
                c.level,
                c.active,
                LOWER(HEX(c.media_id)) AS media_id,
                COALESCE(ct.meta_title, '') AS meta_title,
                COALESCE(ct.meta_description, '') AS meta_description,
                COALESCE(m.file_name, '') AS media_file_name,
                COALESCE(m.file_extension, '') AS media_file_extension,
                COALESCE(m.path, '') AS media_path
            FROM category c
            LEFT JOIN category_translation ct
                ON ct.category_id = c.id
                AND ct.language_id = ?
            LEFT JOIN media m ON m.id = c.media_id
            WHERE c.type = 'page'
            ORDER BY c.level ASC, c.auto_increment ASC
        ", [$this->db->languageIdBin()]);
    }

    public function fetchUpdatedSince(\DateTimeInterface $since): array
    {
        return $this->db->select("
            SELECT
                LOWER(HEX(c.id)) AS id,
                LOWER(HEX(c.parent_id)) AS parent_id,
                COALESCE(ct.name, '') AS name,
                COALESCE(ct.description, '') AS description,
                c.auto_increment AS sort_order,
                c.level,
                c.active,
                LOWER(HEX(c.media_id)) AS media_id,
                COALESCE(ct.meta_title, '') AS meta_title,
                COALESCE(ct.meta_description, '') AS meta_description,
                COALESCE(m.file_name, '') AS media_file_name,
                COALESCE(m.file_extension, '') AS media_file_extension,
                COALESCE(m.path, '') AS media_path,
                c.updated_at,
                c.created_at
            FROM category c
            LEFT JOIN category_translation ct
                ON ct.category_id = c.id
                AND ct.language_id = ?
            LEFT JOIN media m ON m.id = c.media_id
            WHERE c.type = 'page'
              AND (c.updated_at > ? OR c.created_at > ?)
            ORDER BY c.updated_at ASC, c.created_at ASC
        ", [
            $this->db->languageIdBin(),
            $since->format('Y-m-d H:i:s'),
            $since->format('Y-m-d H:i:s'),
        ]);
    }
}

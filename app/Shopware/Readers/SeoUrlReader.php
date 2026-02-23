<?php

namespace App\Shopware\Readers;

use App\Services\ShopwareDB;

class SeoUrlReader
{
    public function __construct(protected ShopwareDB $db) {}

    public function fetchAllForProducts(): array
    {
        return $this->db->select("
            SELECT
                LOWER(HEX(su.id)) AS id,
                LOWER(HEX(su.foreign_key)) AS foreign_key,
                su.route_name,
                su.path_info,
                su.seo_path_info,
                su.is_canonical
            FROM seo_url su
            WHERE su.route_name = 'frontend.detail.page'
              AND su.is_deleted = 0
              AND su.is_canonical = 1
              AND su.language_id = ?
            ORDER BY su.seo_path_info ASC
        ", [$this->db->languageIdBin()]);
    }

    public function fetchAllForCategories(): array
    {
        return $this->db->select("
            SELECT
                LOWER(HEX(su.id)) AS id,
                LOWER(HEX(su.foreign_key)) AS foreign_key,
                su.route_name,
                su.path_info,
                su.seo_path_info,
                su.is_canonical
            FROM seo_url su
            WHERE su.route_name = 'frontend.navigation.page'
              AND su.is_deleted = 0
              AND su.is_canonical = 1
              AND su.language_id = ?
            ORDER BY su.seo_path_info ASC
        ", [$this->db->languageIdBin()]);
    }

    public function fetchByForeignKey(string $foreignKey): array
    {
        return $this->db->select('
            SELECT
                LOWER(HEX(su.id)) AS id,
                LOWER(HEX(su.foreign_key)) AS foreign_key,
                su.route_name,
                su.path_info,
                su.seo_path_info,
                su.is_canonical
            FROM seo_url su
            WHERE su.foreign_key = UNHEX(?)
              AND su.is_deleted = 0
              AND su.language_id = ?
            ORDER BY su.is_canonical DESC, su.seo_path_info ASC
        ', [$foreignKey, $this->db->languageIdBin()]);
    }

    public function fetchAllForCmsPages(): array
    {
        return $this->db->select("
            SELECT
                LOWER(HEX(su.id)) AS id,
                LOWER(HEX(su.foreign_key)) AS foreign_key,
                su.route_name,
                su.path_info,
                su.seo_path_info,
                su.is_canonical
            FROM seo_url su
            WHERE su.route_name LIKE 'frontend.cms.page%'
              AND su.is_deleted = 0
              AND su.is_canonical = 1
              AND su.language_id = ?
            ORDER BY su.seo_path_info ASC
        ", [$this->db->languageIdBin()]);
    }

    public function fetchUpdatedSince(\DateTimeInterface $since): array
    {
        return $this->db->select('
            SELECT
                LOWER(HEX(su.id)) AS id,
                LOWER(HEX(su.foreign_key)) AS foreign_key,
                su.route_name,
                su.path_info,
                su.seo_path_info,
                su.is_canonical,
                su.updated_at,
                su.created_at
            FROM seo_url su
            WHERE su.is_deleted = 0
              AND su.is_canonical = 1
              AND su.language_id = ?
              AND (su.updated_at > ? OR su.created_at > ?)
            ORDER BY su.updated_at ASC, su.created_at ASC
        ', [
            $this->db->languageIdBin(),
            $since->format('Y-m-d H:i:s'),
            $since->format('Y-m-d H:i:s'),
        ]);
    }
}

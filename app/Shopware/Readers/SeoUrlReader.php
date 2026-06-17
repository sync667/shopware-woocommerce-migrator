<?php

namespace App\Shopware\Readers;

use App\Services\ShopwareDB;

class SeoUrlReader
{
    public function __construct(protected ShopwareDB $db) {}

    public function fetchAllForProducts(): array
    {
        return $this->fetchByRoute('frontend.detail.page');
    }

    public function fetchAllForCategories(): array
    {
        return $this->fetchByRoute('frontend.navigation.page');
    }

    /**
     * Multi-storefront shops have one canonical `seo_url` row per (entity, sales_channel)
     * — meaning the same path /shop/foo can appear several times pointing at the same
     * Shopware product. Pushing every row to Redirection would create duplicate-source
     * rules. Dedupe per (seo_path_info, foreign_key) so each ENTITY gets one rule; the
     * job's downstream `source_collision` skip then handles the rarer real cross-entity
     * collisions (when two DIFFERENT products historically shared one SEO path).
     */
    private function fetchByRoute(string $routeName): array
    {
        return $this->db->select('
            SELECT id, foreign_key, route_name, path_info, seo_path_info, is_canonical
            FROM (
                SELECT
                    LOWER(HEX(su.id)) AS id,
                    LOWER(HEX(su.foreign_key)) AS foreign_key,
                    su.route_name,
                    su.path_info,
                    su.seo_path_info,
                    su.is_canonical,
                    ROW_NUMBER() OVER (
                        PARTITION BY su.seo_path_info, su.foreign_key
                        ORDER BY
                            COALESCE(su.is_canonical, 0) DESC,
                            (su.sales_channel_id IS NULL) DESC,
                            su.created_at ASC,
                            su.id ASC
                    ) AS rn
                FROM seo_url su
                WHERE su.route_name = ?
                  AND su.is_deleted = 0
                  AND su.language_id = ?
                  AND su.seo_path_info IS NOT NULL
                  AND su.seo_path_info != ?
            ) t
            WHERE rn = 1
            ORDER BY is_canonical DESC, seo_path_info ASC
        ', [$routeName, $this->db->languageIdBin(), '']);
    }

    /**
     * Fetch deduped seo_url rows as (id, seo_path_info) pairs. The dispatcher
     * uses seo_path_info to detect cross-entity source collisions before chunking
     * so the work-units it ships to batch workers are already a clean set.
     *
     * Soft-deleted rows (is_deleted = 1) are intentionally INCLUDED: when a product
     * variant or category is renamed Shopware soft-deletes the old seo_url, but that
     * URL is still indexed by search engines and linked externally — it must still
     * redirect. A live row always wins over a deleted one for the same (path, entity),
     * and the batch job harmlessly skips any whose entity was fully removed (orphan).
     *
     * @return array<int, object>
     */
    public function fetchAllIds(): array
    {
        return $this->db->select('
            SELECT id, seo_path_info
            FROM (
                SELECT
                    LOWER(HEX(su.id)) AS id,
                    su.seo_path_info,
                    su.foreign_key,
                    su.is_deleted,
                    ROW_NUMBER() OVER (
                        PARTITION BY su.seo_path_info, su.foreign_key
                        ORDER BY
                            su.is_deleted ASC,
                            COALESCE(su.is_canonical, 0) DESC,
                            (su.sales_channel_id IS NULL) DESC,
                            su.created_at ASC,
                            su.id ASC
                    ) AS rn
                FROM seo_url su
                WHERE (su.route_name IN (?, ?, ?) OR su.route_name LIKE ?)
                  AND su.language_id = ?
                  AND su.seo_path_info IS NOT NULL
                  AND su.seo_path_info != ?
            ) t
            WHERE rn = 1
            ORDER BY is_deleted ASC, id ASC
        ', [
            'frontend.detail.page',
            'frontend.navigation.page',
            'frontend.cms.page',
            'frontend.cms.page%',
            $this->db->languageIdBin(),
            '',
        ]);
    }

    /**
     * Fetch full seo_url row data for the given IDs. Used by MigrateSeoUrlBatchJob
     * to pull just the chunk it owns.
     *
     * @param  array<int, string>  $ids  lowercase-hex shopware seo_url ids
     * @return array<int, object>
     */
    public function fetchByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), 'UNHEX(?)'));

        return $this->db->select("
            SELECT
                LOWER(HEX(su.id)) AS id,
                LOWER(HEX(su.foreign_key)) AS foreign_key,
                su.route_name,
                su.path_info,
                su.seo_path_info,
                su.is_canonical
            FROM seo_url su
            WHERE su.id IN ({$placeholders})
        ", $ids);
    }

    public function fetchByForeignKey(string $foreignKey): array
    {
        return $this->db->select('
            SELECT id, foreign_key, route_name, path_info, seo_path_info, is_canonical
            FROM (
                SELECT
                    LOWER(HEX(su.id)) AS id,
                    LOWER(HEX(su.foreign_key)) AS foreign_key,
                    su.route_name,
                    su.path_info,
                    su.seo_path_info,
                    su.is_canonical,
                    ROW_NUMBER() OVER (
                        PARTITION BY su.seo_path_info, su.foreign_key
                        ORDER BY
                            COALESCE(su.is_canonical, 0) DESC,
                            (su.sales_channel_id IS NULL) DESC,
                            su.created_at ASC,
                            su.id ASC
                    ) AS rn
                FROM seo_url su
                WHERE su.foreign_key = UNHEX(?)
                  AND su.is_deleted = 0
                  AND su.language_id = ?
                  AND su.seo_path_info IS NOT NULL
                  AND su.seo_path_info != ?
            ) t
            WHERE rn = 1
            ORDER BY is_canonical DESC, seo_path_info ASC
        ', [$foreignKey, $this->db->languageIdBin(), '']);
    }

    public function fetchAllForCmsPages(): array
    {
        return $this->db->select("
            SELECT id, foreign_key, route_name, path_info, seo_path_info, is_canonical
            FROM (
                SELECT
                    LOWER(HEX(su.id)) AS id,
                    LOWER(HEX(su.foreign_key)) AS foreign_key,
                    su.route_name,
                    su.path_info,
                    su.seo_path_info,
                    su.is_canonical,
                    ROW_NUMBER() OVER (
                        PARTITION BY su.seo_path_info, su.foreign_key
                        ORDER BY
                            COALESCE(su.is_canonical, 0) DESC,
                            (su.sales_channel_id IS NULL) DESC,
                            su.created_at ASC,
                            su.id ASC
                    ) AS rn
                FROM seo_url su
                WHERE su.route_name LIKE 'frontend.cms.page%'
                  AND su.is_deleted = 0
                  AND su.language_id = ?
                  AND su.seo_path_info IS NOT NULL
                  AND su.seo_path_info != ?
            ) t
            WHERE rn = 1
            ORDER BY is_canonical DESC, seo_path_info ASC
        ", [$this->db->languageIdBin(), '']);
    }

    public function fetchUpdatedSince(\DateTimeInterface $since): array
    {
        return $this->db->select('
            SELECT id, foreign_key, route_name, path_info, seo_path_info, is_canonical, updated_at, created_at
            FROM (
                SELECT
                    LOWER(HEX(su.id)) AS id,
                    LOWER(HEX(su.foreign_key)) AS foreign_key,
                    su.route_name,
                    su.path_info,
                    su.seo_path_info,
                    su.is_canonical,
                    su.updated_at,
                    su.created_at,
                    ROW_NUMBER() OVER (
                        PARTITION BY su.seo_path_info, su.foreign_key
                        ORDER BY
                            COALESCE(su.is_canonical, 0) DESC,
                            (su.sales_channel_id IS NULL) DESC,
                            su.updated_at DESC,
                            su.id ASC
                    ) AS rn
                FROM seo_url su
                WHERE su.is_deleted = 0
                  AND su.language_id = ?
                  AND su.route_name IN (?, ?, ?)
                  AND su.seo_path_info IS NOT NULL
                  AND su.seo_path_info != ?
                  AND (su.updated_at > ? OR su.created_at > ?)
            ) t
            WHERE rn = 1
            ORDER BY updated_at ASC, created_at ASC
        ', [
            $this->db->languageIdBin(),
            'frontend.detail.page',
            'frontend.navigation.page',
            'frontend.cms.page',
            '',
            $since->format('Y-m-d H:i:s'),
            $since->format('Y-m-d H:i:s'),
        ]);
    }
}

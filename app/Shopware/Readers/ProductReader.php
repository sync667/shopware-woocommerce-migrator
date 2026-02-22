<?php

namespace App\Shopware\Readers;

use App\Services\ShopwareDB;

class ProductReader
{
    public function __construct(protected ShopwareDB $db) {}

    public function fetchAllParents(): array
    {
        return $this->db->select("
            SELECT
                LOWER(HEX(p.id)) AS id,
                LOWER(HEX(p.parent_id)) AS parent_id,
                p.product_number AS sku,
                p.active,
                p.stock,
                p.is_closeout AS manage_stock,
                p.weight,
                p.width,
                p.height,
                p.length AS depth,
                p.price,
                p.product_type AS type,
                LOWER(HEX(p.tax_id)) AS tax_id,
                LOWER(HEX(p.product_manufacturer_id)) AS manufacturer_id,
                LOWER(HEX(p.cover_id)) AS cover_id,
                COALESCE(pt.name, '') AS name,
                COALESCE(pt.description, '') AS description,
                COALESCE(pt.meta_title, '') AS meta_title,
                COALESCE(pt.meta_description, '') AS meta_description,
                COALESCE(pt.custom_search_keywords, '') AS keywords
            FROM product p
            LEFT JOIN product_translation pt
                ON pt.product_id = p.id
                AND pt.product_version_id = p.version_id
                AND pt.language_id = ?
            WHERE p.version_id = ?
              AND p.parent_id IS NULL
            ORDER BY pt.name ASC
        ", [$this->db->languageIdBin(), $this->db->liveVersionIdBin()]);
    }

    public function fetchVariants(string $parentId): array
    {
        return $this->db->select("
            SELECT
                LOWER(HEX(p.id)) AS id,
                p.product_number AS sku,
                p.active,
                p.stock,
                p.is_closeout AS manage_stock,
                p.weight,
                p.price,
                LOWER(HEX(p.cover_id)) AS cover_id
            FROM product p
            WHERE p.version_id = ?
              AND p.parent_id = UNHEX(?)
            ORDER BY p.product_number ASC
        ", [$this->db->liveVersionIdBin(), $parentId]);
    }

    public function fetchMedia(string $productId): array
    {
        return $this->db->select("
            SELECT
                LOWER(HEX(pm.media_id)) AS media_id,
                pm.position,
                COALESCE(m.file_name, '') AS file_name,
                COALESCE(m.file_extension, '') AS file_extension,
                COALESCE(m.path, '') AS path,
                COALESCE(mt.alt, '') AS alt,
                COALESCE(mt.title, '') AS title
            FROM product_media pm
            INNER JOIN media m ON m.id = pm.media_id
            LEFT JOIN media_translation mt
                ON mt.media_id = m.id
                AND mt.language_id = ?
            WHERE pm.product_id = UNHEX(?)
              AND pm.product_version_id = ?
            ORDER BY pm.position ASC
        ", [$this->db->languageIdBin(), $productId, $this->db->liveVersionIdBin()]);
    }

    public function fetchCategories(string $productId): array
    {
        return $this->db->select("
            SELECT LOWER(HEX(pc.category_id)) AS category_id
            FROM product_category pc
            WHERE pc.product_id = UNHEX(?)
              AND pc.product_version_id = ?
        ", [$productId, $this->db->liveVersionIdBin()]);
    }

    public function fetchConfiguratorSettings(string $productId): array
    {
        return $this->db->select("
            SELECT
                LOWER(HEX(pcs.id)) AS id,
                LOWER(HEX(pcs.option_id)) AS option_id,
                COALESCE(pgot.name, '') AS option_name,
                COALESCE(pgt.name, '') AS group_name,
                LOWER(HEX(pgo.property_group_id)) AS group_id
            FROM product_configurator_setting pcs
            INNER JOIN property_group_option pgo ON pgo.id = pcs.option_id
            INNER JOIN property_group pg ON pg.id = pgo.property_group_id
            LEFT JOIN property_group_option_translation pgot
                ON pgot.property_group_option_id = pgo.id
                AND pgot.language_id = ?
            LEFT JOIN property_group_translation pgt
                ON pgt.property_group_id = pg.id
                AND pgt.language_id = ?
            WHERE pcs.product_id = UNHEX(?)
              AND pcs.product_version_id = ?
        ", [
            $this->db->languageIdBin(),
            $this->db->languageIdBin(),
            $productId,
            $this->db->liveVersionIdBin(),
        ]);
    }

    public function fetchProperties(string $productId): array
    {
        return $this->db->select("
            SELECT
                LOWER(HEX(pp.option_id)) AS option_id,
                COALESCE(pgot.name, '') AS option_name,
                COALESCE(pgt.name, '') AS group_name,
                LOWER(HEX(pgo.property_group_id)) AS group_id
            FROM product_property pp
            INNER JOIN property_group_option pgo ON pgo.id = pp.option_id
            INNER JOIN property_group pg ON pg.id = pgo.property_group_id
            LEFT JOIN property_group_option_translation pgot
                ON pgot.property_group_option_id = pgo.id
                AND pgot.language_id = ?
            LEFT JOIN property_group_translation pgt
                ON pgt.property_group_id = pg.id
                AND pgt.language_id = ?
            WHERE pp.product_id = UNHEX(?)
              AND pp.product_version_id = ?
        ", [
            $this->db->languageIdBin(),
            $this->db->languageIdBin(),
            $productId,
            $this->db->liveVersionIdBin(),
        ]);
    }

    public function fetchTags(string $productId): array
    {
        return $this->db->select("
            SELECT COALESCE(tt.name, t.name) AS name
            FROM product_tag ptag
            INNER JOIN tag t ON t.id = ptag.tag_id
            LEFT JOIN tag_translation tt
                ON tt.tag_id = t.id
                AND tt.language_id = ?
            WHERE ptag.product_id = UNHEX(?)
              AND ptag.product_version_id = ?
        ", [$this->db->languageIdBin(), $productId, $this->db->liveVersionIdBin()]);
    }

    public function fetchCrossSells(string $productId): array
    {
        return $this->db->select("
            SELECT
                LOWER(HEX(pcsa.product_id)) AS target_product_id,
                pcs.type
            FROM product_cross_selling pcs
            INNER JOIN product_cross_selling_assigned_products pcsa
                ON pcsa.cross_selling_id = pcs.id
            WHERE pcs.product_id = UNHEX(?)
              AND pcs.product_version_id = ?
        ", [$productId, $this->db->liveVersionIdBin()]);
    }

    public function fetchVariantOptions(string $variantId): array
    {
        return $this->db->select("
            SELECT
                LOWER(HEX(po.option_id)) AS option_id,
                COALESCE(pgot.name, '') AS option_name,
                COALESCE(pgt.name, '') AS group_name,
                LOWER(HEX(pgo.property_group_id)) AS group_id
            FROM product_option po
            INNER JOIN property_group_option pgo ON pgo.id = po.option_id
            INNER JOIN property_group pg ON pg.id = pgo.property_group_id
            LEFT JOIN property_group_option_translation pgot
                ON pgot.property_group_option_id = pgo.id
                AND pgot.language_id = ?
            LEFT JOIN property_group_translation pgt
                ON pgt.property_group_id = pg.id
                AND pgt.language_id = ?
            WHERE po.product_id = UNHEX(?)
              AND po.product_version_id = ?
        ", [
            $this->db->languageIdBin(),
            $this->db->languageIdBin(),
            $variantId,
            $this->db->liveVersionIdBin(),
        ]);
    }
}

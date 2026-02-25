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
                CASE WHEN p.child_count > 0 THEN 'grouped' ELSE 'simple' END AS type,
                LOWER(HEX(p.tax_id)) AS tax_id,
                LOWER(HEX(p.product_manufacturer_id)) AS manufacturer_id,
                LOWER(HEX(p.product_media_id)) AS cover_id,
                COALESCE(pt.name, '') AS name,
                COALESCE(pt.description, '') AS description,
                COALESCE(pt.meta_title, '') AS meta_title,
                COALESCE(pt.meta_description, '') AS meta_description,
                COALESCE(pt.custom_search_keywords, '') AS keywords,
                p.ean,
                p.manufacturer_number,
                p.min_purchase,
                p.max_purchase,
                p.purchase_steps,
                p.purchase_unit,
                p.reference_unit,
                p.shipping_free,
                p.mark_as_topseller,
                p.available
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

    public function fetchOne(string $productId): ?object
    {
        $results = $this->db->select("
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
                CASE WHEN p.child_count > 0 THEN 'grouped' ELSE 'simple' END AS type,
                LOWER(HEX(p.tax_id)) AS tax_id,
                LOWER(HEX(p.product_manufacturer_id)) AS manufacturer_id,
                LOWER(HEX(p.product_media_id)) AS cover_id,
                COALESCE(pt.name, '') AS name,
                COALESCE(pt.description, '') AS description,
                COALESCE(pt.meta_title, '') AS meta_title,
                COALESCE(pt.meta_description, '') AS meta_description,
                COALESCE(pt.custom_search_keywords, '') AS keywords,
                p.ean,
                p.manufacturer_number,
                p.min_purchase,
                p.max_purchase,
                p.purchase_steps,
                p.purchase_unit,
                p.reference_unit,
                p.shipping_free,
                p.mark_as_topseller,
                p.available,
                pt.custom_fields,
                p.created_at,
                (SELECT MAX(pv.visibility)
                 FROM product_visibility pv
                 WHERE pv.product_id = p.id
                   AND pv.product_version_id = p.version_id) AS max_visibility
            FROM product p
            LEFT JOIN product_translation pt
                ON pt.product_id = p.id
                AND pt.product_version_id = p.version_id
                AND pt.language_id = ?
            WHERE p.id = UNHEX(?)
              AND p.version_id = ?
        ", [$this->db->languageIdBin(), $productId, $this->db->liveVersionIdBin()]);

        return $results[0] ?? null;
    }

    public function fetchVariants(string $parentId): array
    {
        return $this->db->select('
            SELECT
                LOWER(HEX(p.id)) AS id,
                p.product_number AS sku,
                p.active,
                p.stock,
                p.is_closeout AS manage_stock,
                p.weight,
                p.price,
                LOWER(HEX(p.product_media_id)) AS cover_id
            FROM product p
            WHERE p.version_id = ?
              AND p.parent_id = UNHEX(?)
            ORDER BY p.product_number ASC
        ', [$this->db->liveVersionIdBin(), $parentId]);
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
        return $this->db->select('
            SELECT LOWER(HEX(pc.category_id)) AS category_id
            FROM product_category pc
            WHERE pc.product_id = UNHEX(?)
              AND pc.product_version_id = ?
        ', [$productId, $this->db->liveVersionIdBin()]);
    }

    public function fetchConfiguratorSettings(string $productId): array
    {
        return $this->db->select("
            SELECT
                LOWER(HEX(pcs.id)) AS id,
                LOWER(HEX(pcs.property_group_option_id)) AS option_id,
                COALESCE(pgot.name, '') AS option_name,
                COALESCE(pgt.name, '') AS group_name,
                LOWER(HEX(pgo.property_group_id)) AS group_id
            FROM product_configurator_setting pcs
            INNER JOIN property_group_option pgo ON pgo.id = pcs.property_group_option_id
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
                LOWER(HEX(pp.property_group_option_id)) AS option_id,
                COALESCE(pgot.name, '') AS option_name,
                COALESCE(pgt.name, '') AS group_name,
                LOWER(HEX(pgo.property_group_id)) AS group_id
            FROM product_property pp
            INNER JOIN property_group_option pgo ON pgo.id = pp.property_group_option_id
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
        return $this->db->select('
            SELECT t.name AS name
            FROM product_tag ptag
            INNER JOIN tag t ON t.id = ptag.tag_id
            WHERE ptag.product_id = UNHEX(?)
              AND ptag.product_version_id = ?
        ', [$productId, $this->db->liveVersionIdBin()]);
    }

    public function fetchCrossSells(string $productId): array
    {
        // Supports both static (crossSelling) and dynamic (productStream) cross-sell types.
        // For productStream, products are resolved from product_stream_mapping (Shopware's cache).
        return $this->db->select('
            SELECT DISTINCT
                LOWER(HEX(COALESCE(pcsa.product_id, psm.product_id))) AS target_product_id,
                pcs.type
            FROM product_cross_selling pcs
            LEFT JOIN product_cross_selling_assigned_products pcsa
                ON pcsa.cross_selling_id = pcs.id
                AND pcs.type != \'productStream\'
            LEFT JOIN product_stream_mapping psm
                ON psm.product_stream_id = pcs.product_stream_id
                AND pcs.type = \'productStream\'
                AND psm.product_version_id = ?
            WHERE pcs.product_id = UNHEX(?)
              AND pcs.product_version_id = ?
              AND (pcsa.product_id IS NOT NULL OR psm.product_id IS NOT NULL)
        ', [$this->db->liveVersionIdBin(), $productId, $this->db->liveVersionIdBin()]);
    }

    public function fetchVariantOptions(string $variantId): array
    {
        return $this->db->select("
            SELECT
                LOWER(HEX(po.property_group_option_id)) AS option_id,
                COALESCE(pgot.name, '') AS option_name,
                COALESCE(pgt.name, '') AS group_name,
                LOWER(HEX(pgo.property_group_id)) AS group_id
            FROM product_option po
            INNER JOIN property_group_option pgo ON pgo.id = po.property_group_option_id
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

    /**
     * Fetch products updated since given timestamp (for delta migration)
     */
    public function fetchUpdatedSince(\DateTimeInterface $since): array
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
                CASE WHEN p.child_count > 0 THEN 'grouped' ELSE 'simple' END AS type,
                LOWER(HEX(p.tax_id)) AS tax_id,
                LOWER(HEX(p.product_manufacturer_id)) AS manufacturer_id,
                LOWER(HEX(p.product_media_id)) AS cover_id,
                COALESCE(pt.name, '') AS name,
                COALESCE(pt.description, '') AS description,
                COALESCE(pt.meta_title, '') AS meta_title,
                COALESCE(pt.meta_description, '') AS meta_description,
                COALESCE(pt.custom_search_keywords, '') AS keywords,
                p.ean,
                p.manufacturer_number,
                p.min_purchase,
                p.max_purchase,
                p.purchase_steps,
                p.purchase_unit,
                p.reference_unit,
                p.shipping_free,
                p.mark_as_topseller,
                p.available,
                p.updated_at,
                p.created_at
            FROM product p
            LEFT JOIN product_translation pt
                ON pt.product_id = p.id
                AND pt.product_version_id = p.version_id
                AND pt.language_id = ?
            WHERE p.version_id = ?
              AND p.parent_id IS NULL
              AND (p.updated_at > ? OR p.created_at > ?)
            ORDER BY p.updated_at ASC, p.created_at ASC
        ", [
            $this->db->languageIdBin(),
            $this->db->liveVersionIdBin(),
            $since->format('Y-m-d H:i:s'),
            $since->format('Y-m-d H:i:s'),
        ]);
    }
}

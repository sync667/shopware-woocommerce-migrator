<?php

namespace App\Shopware\Readers;

use App\Services\ShopwareDB;

class ProductReader
{
    public function __construct(protected ShopwareDB $db) {}

    public function fetchAllParents(): array
    {
        [$maxVisibilitySql, $params] = $this->maxVisibilityClause();

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
                LOWER(HEX(p.main_variant_id)) AS main_variant_id,
                LOWER(HEX(p.cms_page_id)) AS cms_page_id,
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
                p.purchase_prices,
                p.release_date,
                COALESCE(dtt.name, '') AS delivery_time_name,
                {$maxVisibilitySql} AS max_visibility
            FROM product p
            LEFT JOIN product_translation pt
                ON pt.product_id = p.id
                AND pt.product_version_id = p.version_id
                AND pt.language_id = ?
            LEFT JOIN delivery_time_translation dtt
                ON dtt.delivery_time_id = p.delivery_time_id
                AND dtt.language_id = ?
            WHERE p.version_id = ?
              AND p.parent_id IS NULL
            ORDER BY pt.name ASC
        ", array_merge($params, [$this->db->languageIdBin(), $this->db->languageIdBin(), $this->db->liveVersionIdBin()]));
    }

    /** @return array{0:string,1:array<int,mixed>} sql, params_to_prepend */
    protected function maxVisibilityClause(): array
    {
        $primary = $this->db->primarySalesChannel();

        if ($primary === null) {
            return [
                '(SELECT MAX(pv.visibility)
                  FROM product_visibility pv
                  WHERE pv.product_id = p.id
                    AND pv.product_version_id = p.version_id)',
                [],
            ];
        }

        return [
            '(SELECT MAX(pv.visibility)
              FROM product_visibility pv
              JOIN sales_channel_translation sct
                ON sct.sales_channel_id = pv.sales_channel_id
                AND sct.language_id = ?
              WHERE pv.product_id = p.id
                AND pv.product_version_id = p.version_id
                AND sct.name = ?)',
            [$this->db->languageIdBin(), $primary],
        ];
    }

    public function fetchOne(string $productId): ?object
    {
        [$maxVisibilitySql, $params] = $this->maxVisibilityClause();

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
                LOWER(HEX(p.main_variant_id)) AS main_variant_id,
                LOWER(HEX(p.cms_page_id)) AS cms_page_id,
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
                p.purchase_prices,
                p.release_date,
                pt.custom_fields,
                p.created_at,
                COALESCE(dtt.name, '') AS delivery_time_name,
                {$maxVisibilitySql} AS max_visibility
            FROM product p
            LEFT JOIN product_translation pt
                ON pt.product_id = p.id
                AND pt.product_version_id = p.version_id
                AND pt.language_id = ?
            LEFT JOIN delivery_time_translation dtt
                ON dtt.delivery_time_id = p.delivery_time_id
                AND dtt.language_id = ?
            WHERE p.id = UNHEX(?)
              AND p.version_id = ?
        ", array_merge($params, [$this->db->languageIdBin(), $this->db->languageIdBin(), $productId, $this->db->liveVersionIdBin()]));

        return $results[0] ?? null;
    }

    public function fetchVariants(string $parentId): array
    {
        // Shopware 6 variants inherit NULL fields from the parent product.
        // All fields marked @Inherited() in ProductDefinition are COALESCED here
        // so that variants which have not overridden a field get the parent's value.
        //
        // Display order: derived from MIN(product_configurator_setting.position)
        // across the variant's option values (operator-set on the parent product).
        // Single-axis configs (Size only, Color only) get exact storefront order;
        // multi-axis variants fall back to the lowest-numbered option's position
        // which still beats alphabetic SKU sort. Variants with no configurator
        // override (e.g. ad-hoc colors) land at 9999 and tie-break by SKU.
        return $this->db->select('
            SELECT
                LOWER(HEX(p.id)) AS id,
                p.product_number AS sku,
                COALESCE(p.active, parent.active) AS active,
                p.stock,
                COALESCE(p.available, parent.available) AS available,
                COALESCE(p.is_closeout, parent.is_closeout) AS manage_stock,
                COALESCE(p.weight, parent.weight) AS weight,
                COALESCE(p.width, parent.width) AS width,
                COALESCE(p.height, parent.height) AS height,
                COALESCE(p.length, parent.length) AS depth,
                COALESCE(p.price, parent.price) AS price,
                COALESCE(p.ean, parent.ean) AS ean,
                COALESCE(p.manufacturer_number, parent.manufacturer_number) AS manufacturer_number,
                COALESCE(p.shipping_free, parent.shipping_free) AS shipping_free,
                COALESCE(p.min_purchase, parent.min_purchase) AS min_purchase,
                COALESCE(p.max_purchase, parent.max_purchase) AS max_purchase,
                COALESCE(p.purchase_steps, parent.purchase_steps) AS purchase_steps,
                LOWER(HEX(p.product_media_id)) AS cover_id,
                COALESCE((
                    SELECT MIN(pcs.position)
                    FROM product_option po
                    JOIN product_configurator_setting pcs
                        ON pcs.property_group_option_id = po.property_group_option_id
                        AND pcs.product_id = p.parent_id
                        AND pcs.product_version_id = p.parent_version_id
                    WHERE po.product_id = p.id
                      AND po.product_version_id = p.version_id
                ), 9999) AS display_order
            FROM product p
            INNER JOIN product parent
                ON parent.id = p.parent_id
                AND parent.version_id = p.version_id
            WHERE p.version_id = ?
              AND p.parent_id = UNHEX(?)
            ORDER BY display_order ASC, p.product_number ASC
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
                COALESCE(mt.alt, '') AS alt,
                COALESCE(mt.title, '') AS title,
                FLOOR(UNIX_TIMESTAMP(m.uploaded_at)) AS uploaded_at
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

    /** @return array<int, object> */
    public function fetchAllPropertyGroups(): array
    {
        return $this->db->select("
            SELECT
                LOWER(HEX(pg.id)) AS group_id,
                COALESCE(pgt.name, '') AS group_name
            FROM property_group pg
            LEFT JOIN property_group_translation pgt
                ON pgt.property_group_id = pg.id
                AND pgt.language_id = ?
            WHERE EXISTS (
                SELECT 1 FROM property_group_option pgo
                WHERE pgo.property_group_id = pg.id
            )
            ORDER BY pgt.name ASC
        ", [$this->db->languageIdBin()]);
    }

    public function fetchConfiguratorSettings(string $productId): array
    {
        return $this->db->select("
            SELECT
                LOWER(HEX(pcs.id)) AS id,
                LOWER(HEX(pcs.property_group_option_id)) AS option_id,
                COALESCE(pgot.name, '') AS option_name,
                COALESCE(pgt.name, '') AS group_name,
                LOWER(HEX(pgo.property_group_id)) AS group_id,
                pcs.position AS position
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
            ORDER BY pcs.position ASC, pgot.name ASC
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

    /**
     * Returns the primary category id for a product on the chosen sales channel.
     * Shopware exposes this via `main_category` — used by SEO plugins (Yoast etc.)
     * to pick the "real" breadcrumb when a product is filed under several categories.
     * Returns null when no main_category row exists.
     */
    public function fetchMainCategoryId(string $productId): ?string
    {
        // Most production rows have a non-null sales_channel_id (the field is sometimes
        // declared NOT NULL by the plugin). NULL is preferred (= "all channels") when
        // present, then sales_channel_id and category_id are used as deterministic
        // tiebreakers so repeated migrations pick the same row.
        $results = $this->db->select('
            SELECT LOWER(HEX(mc.category_id)) AS category_id
            FROM main_category mc
            WHERE mc.product_id = UNHEX(?)
              AND mc.product_version_id = ?
            ORDER BY (mc.sales_channel_id IS NULL) DESC, mc.sales_channel_id ASC, mc.category_id ASC
            LIMIT 1
        ', [$productId, $this->db->liveVersionIdBin()]);

        return $results[0]->category_id ?? null;
    }

    /**
     * Reads the latest entry from the Crehler Omnibus plugin table (Polish "Dyrektywa
     * Omnibus" — required to display the lowest 30-day price next to every promo price).
     * The plugin table is optional; absence returns null and the migrator skips the field.
     */
    public function fetchOmnibusLowestPrice(string $productId): ?string
    {
        try {
            $results = $this->db->select('
                SELECT op.price_gross
                FROM crehler_omnibus_prices op
                WHERE op.product_id = UNHEX(?)
                ORDER BY op.created_at DESC, op.id DESC
                LIMIT 1
            ', [$productId]);
        } catch (\Throwable) {
            return null;
        }

        $value = $results[0]->price_gross ?? null;
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        // The DSP regulation talks about the lowest price; a literal "0" is not a valid
        // lowest price for a paid product (production has 224 such rows from staging tests).
        if ((float) $value <= 0) {
            return null;
        }

        return $value;
    }

    /**
     * @return array<int, object> rows with `type` and `config` JSON string
     */
    public function fetchVideoSlots(string $cmsPageId): array
    {
        if ($cmsPageId === '') {
            return [];
        }

        return $this->db->select("
            SELECT cs.type, cst.config, cse.position AS section_pos, cb.position AS block_pos, cs.slot AS slot_name
            FROM cms_slot cs
            JOIN cms_block cb ON cb.id = cs.cms_block_id
            JOIN cms_section cse ON cse.id = cb.cms_section_id
            LEFT JOIN cms_slot_translation cst
                ON cst.cms_slot_id = cs.id
                AND cst.cms_slot_version_id = cs.version_id
                AND cst.language_id = ?
            WHERE cse.cms_page_id = UNHEX(?)
              AND cs.version_id = ?
              AND cs.type IN ('youtube-video', 'vimeo-video')
            ORDER BY cse.position ASC, cb.position ASC, cs.slot ASC
        ", [$this->db->languageIdBin(), $cmsPageId, $this->db->liveVersionIdBin()]);
    }

    public function fetchCrossSells(string $productId): array
    {
        // productList = manual list, productStream = dynamic (resolved via product_stream_mapping).
        // pct.name is the operator-defined group label ("ZOBACZ RÓWNIEŻ", etc.) used
        // to split into WC upsell vs cross-sell at migrator settings level.
        return $this->db->select('
            SELECT DISTINCT
                LOWER(HEX(COALESCE(pcsa.product_id, psm.product_id))) AS target_product_id,
                pcs.type,
                COALESCE(pct.name, \'\') AS group_name
            FROM product_cross_selling pcs
            LEFT JOIN product_cross_selling_translation pct
                ON pct.product_cross_selling_id = pcs.id
                AND pct.language_id = ?
            LEFT JOIN product_cross_selling_assigned_products pcsa
                ON pcsa.cross_selling_id = pcs.id
                AND pcs.type != \'productStream\'
            LEFT JOIN product_stream_mapping psm
                ON psm.product_stream_id = pcs.product_stream_id
                AND pcs.type = \'productStream\'
                AND psm.product_version_id = ?
            WHERE pcs.product_id = UNHEX(?)
              AND pcs.product_version_id = ?
              AND pcs.active = 1
              AND (pcsa.product_id IS NOT NULL OR psm.product_id IS NOT NULL)
        ', [$this->db->languageIdBin(), $this->db->liveVersionIdBin(), $productId, $this->db->liveVersionIdBin()]);
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
                LOWER(HEX(p.main_variant_id)) AS main_variant_id,
                LOWER(HEX(p.cms_page_id)) AS cms_page_id,
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
                p.created_at,
                COALESCE(dtt.name, '') AS delivery_time_name
            FROM product p
            LEFT JOIN product_translation pt
                ON pt.product_id = p.id
                AND pt.product_version_id = p.version_id
                AND pt.language_id = ?
            LEFT JOIN delivery_time_translation dtt
                ON dtt.delivery_time_id = p.delivery_time_id
                AND dtt.language_id = ?
            WHERE p.version_id = ?
              AND p.parent_id IS NULL
              AND (p.updated_at > ? OR p.created_at > ?)
            ORDER BY p.updated_at ASC, p.created_at ASC
        ", [
            $this->db->languageIdBin(),
            $this->db->languageIdBin(),
            $this->db->liveVersionIdBin(),
            $since->format('Y-m-d H:i:s'),
            $since->format('Y-m-d H:i:s'),
        ]);
    }
}

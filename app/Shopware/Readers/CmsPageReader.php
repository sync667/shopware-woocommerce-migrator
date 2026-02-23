<?php

namespace App\Shopware\Readers;

use App\Services\ShopwareDB;

class CmsPageReader
{
    public function __construct(protected ShopwareDB $db) {}

    /**
     * Fetch all CMS pages (excludes product_detail and product_list templates)
     */
    public function fetchAll(): array
    {
        return $this->db->select("
            SELECT
                LOWER(HEX(cp.id)) AS id,
                cp.type,
                cp.locked,
                cp.created_at,
                cp.updated_at,
                COALESCE(cpt.name, '') AS name,
                cpt.custom_fields
            FROM cms_page cp
            LEFT JOIN cms_page_translation cpt
                ON cpt.cms_page_id = cp.id
                AND cpt.language_id = ?
            WHERE cp.type IN ('page', 'landingpage')
            ORDER BY cp.type ASC, cpt.name ASC
        ", [$this->db->languageIdBin()]);
    }

    /**
     * Fetch specific CMS pages by IDs
     */
    public function fetchByIds(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), 'UNHEX(?)'));

        return $this->db->select("
            SELECT
                LOWER(HEX(cp.id)) AS id,
                cp.type,
                cp.locked,
                cp.created_at,
                cp.updated_at,
                COALESCE(cpt.name, '') AS name,
                cpt.custom_fields
            FROM cms_page cp
            LEFT JOIN cms_page_translation cpt
                ON cpt.cms_page_id = cp.id
                AND cpt.language_id = ?
            WHERE cp.id IN ({$placeholders})
            ORDER BY cp.type ASC, cpt.name ASC
        ", array_merge([$this->db->languageIdBin()], $ids));
    }

    /**
     * Fetch all sections for a page
     */
    public function fetchSections(string $pageId): array
    {
        return $this->db->select('
            SELECT
                LOWER(HEX(id)) AS id,
                type,
                position,
                sizing_mode,
                background_color,
                background_media_mode
            FROM cms_section
            WHERE cms_page_id = UNHEX(?)
            ORDER BY position ASC
        ', [$pageId]);
    }

    /**
     * Fetch all blocks for a section
     */
    public function fetchBlocks(string $sectionId): array
    {
        return $this->db->select('
            SELECT
                LOWER(HEX(id)) AS id,
                type,
                position,
                locked,
                margin_top,
                margin_bottom,
                margin_left,
                margin_right,
                background_color
            FROM cms_block
            WHERE cms_section_id = UNHEX(?)
            ORDER BY position ASC
        ', [$sectionId]);
    }

    /**
     * Fetch all slots for a block
     */
    public function fetchSlots(string $blockId): array
    {
        return $this->db->select("
            SELECT
                LOWER(HEX(cs.id)) AS id,
                cs.type,
                cs.slot,
                cs.locked,
                COALESCE(cst.config, '{}') AS config
            FROM cms_slot cs
            LEFT JOIN cms_slot_translation cst
                ON cst.cms_slot_id = cs.id
                AND cst.language_id = ?
            WHERE cs.cms_block_id = UNHEX(?)
            ORDER BY cs.slot ASC
        ", [$this->db->languageIdBin(), $blockId]);
    }
}

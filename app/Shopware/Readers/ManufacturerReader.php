<?php

namespace App\Shopware\Readers;

use App\Services\ShopwareDB;

class ManufacturerReader
{
    public function __construct(protected ShopwareDB $db) {}

    public function fetchAll(): array
    {
        return $this->db->select("
            SELECT
                LOWER(HEX(pm.id)) AS id,
                COALESCE(pmt.name, '') AS name,
                LOWER(HEX(pm.media_id)) AS media_id,
                COALESCE(m.file_name, '') AS media_file_name,
                COALESCE(m.file_extension, '') AS media_file_extension
            FROM product_manufacturer pm
            LEFT JOIN product_manufacturer_translation pmt
                ON pmt.product_manufacturer_id = pm.id
                AND pmt.language_id = ?
            LEFT JOIN media m ON m.id = pm.media_id
            ORDER BY pmt.name ASC
        ", [$this->db->languageIdBin()]);
    }
}

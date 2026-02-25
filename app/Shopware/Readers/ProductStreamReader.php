<?php

namespace App\Shopware\Readers;

use App\Services\ShopwareDB;

class ProductStreamReader
{
    public function __construct(protected ShopwareDB $db) {}

    public function fetchAll(): array
    {
        return $this->db->select("
            SELECT
                LOWER(HEX(ps.id)) AS id,
                COALESCE(pst.name, '') AS name
            FROM product_stream ps
            LEFT JOIN product_stream_translation pst
                ON pst.product_stream_id = ps.id
                AND pst.language_id = ?
            ORDER BY pst.name ASC
        ", [$this->db->languageIdBin()]);
    }

    public function fetchStreamProducts(string $streamId): array
    {
        return $this->db->select('
            SELECT LOWER(HEX(psm.product_id)) AS product_id
            FROM product_stream_mapping psm
            WHERE psm.product_stream_id = UNHEX(?)
              AND psm.product_version_id = ?
        ', [$streamId, $this->db->liveVersionIdBin()]);
    }
}

<?php

namespace App\Shopware\Readers;

use App\Services\ShopwareDB;

class CustomFieldReader
{
    public function __construct(protected ShopwareDB $db) {}

    public function fetchAll(): array
    {
        return $this->db->select('
            SELECT
                LOWER(HEX(cf.id)) AS id,
                cf.name,
                cf.type,
                cf.config,
                cf.active,
                LOWER(HEX(cf.set_id)) AS set_id
            FROM custom_field cf
            WHERE cf.active = 1
            ORDER BY cf.name ASC
        ');
    }

    public function fetchSets(): array
    {
        return $this->db->select('
            SELECT
                LOWER(HEX(cfs.id)) AS id,
                cfs.name,
                cfs.config,
                cfs.global,
                cfs.position
            FROM custom_field_set cfs
            WHERE cfs.active = 1
            ORDER BY cfs.position ASC
        ');
    }
}

<?php

namespace App\Shopware\Readers;

use App\Services\ShopwareDB;
use Generator;

class CustomerWishlistReader
{
    public function __construct(protected ShopwareDB $db) {}

    /**
     * Stream wishlist entries flattened to one row per (customer, product) pair so
     * the CSV importer doesn't have to reconstruct the join. Yields stdClass.
     *
     * @return Generator<int, object>
     */
    public function stream(): Generator
    {
        $offset = 0;
        $chunk = 500;

        while (true) {
            $rows = $this->db->select(
                "SELECT
                    LOWER(HEX(cwp.id)) AS wishlist_product_id,
                    LOWER(HEX(cw.id)) AS wishlist_id,
                    LOWER(HEX(cw.customer_id)) AS customer_id,
                    c.email AS customer_email,
                    LOWER(HEX(cwp.product_id)) AS product_id,
                    p.product_number AS sku,
                    cwp.created_at AS added_at
                FROM customer_wishlist cw
                INNER JOIN customer_wishlist_product cwp ON cwp.customer_wishlist_id = cw.id
                INNER JOIN customer c ON c.id = cw.customer_id
                LEFT JOIN product p
                    ON p.id = cwp.product_id
                    AND p.version_id = cwp.product_version_id
                ORDER BY cw.customer_id ASC, cwp.created_at ASC
                LIMIT {$chunk} OFFSET {$offset}"
            );

            if ($rows === []) {
                return;
            }

            foreach ($rows as $row) {
                yield $row;
            }

            if (count($rows) < $chunk) {
                return;
            }

            $offset += $chunk;
        }
    }
}

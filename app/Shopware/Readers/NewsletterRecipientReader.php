<?php

namespace App\Shopware\Readers;

use App\Services\ShopwareDB;
use Generator;

class NewsletterRecipientReader
{
    public function __construct(protected ShopwareDB $db) {}

    /**
     * Stream newsletter recipients row-by-row to keep memory bounded on shops with
     * tens of thousands of subscribers. Yields stdClass per row.
     *
     * @return Generator<int, object>
     */
    public function stream(?\DateTimeInterface $since = null): Generator
    {
        $offset = 0;
        $chunk = 500;

        while (true) {
            $where = $since !== null
                ? 'WHERE nr.updated_at > ? OR nr.created_at > ?'
                : '';
            $bindings = $since !== null
                ? [$since->format('Y-m-d H:i:s'), $since->format('Y-m-d H:i:s')]
                : [];

            $rows = $this->db->select(
                "SELECT
                    LOWER(HEX(nr.id)) AS id,
                    nr.email,
                    nr.title,
                    nr.first_name,
                    nr.last_name,
                    nr.zip_code,
                    nr.city,
                    nr.street,
                    nr.status,
                    nr.hash,
                    LOWER(HEX(nr.language_id)) AS language_id,
                    LOWER(HEX(nr.sales_channel_id)) AS sales_channel_id,
                    nr.confirmed_at,
                    nr.created_at,
                    nr.updated_at
                FROM newsletter_recipient nr
                {$where}
                ORDER BY nr.created_at ASC, nr.id ASC
                LIMIT {$chunk} OFFSET {$offset}",
                $bindings
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

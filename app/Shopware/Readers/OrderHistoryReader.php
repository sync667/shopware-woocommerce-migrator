<?php

namespace App\Shopware\Readers;

use App\Services\ShopwareDB;

class OrderHistoryReader
{
    public function __construct(protected ShopwareDB $db) {}

    /**
     * Fetch every order state transition grouped by Shopware order id.
     * Returns ['sw_order_id_hex' => [{from_state, to_state, transition_at}, …]].
     *
     * @return array<string, array<int, object>>
     */
    public function fetchAllByOrder(): array
    {
        $rows = $this->db->select('
            SELECT
                JSON_UNQUOTE(JSON_EXTRACT(CAST(smh.entity_id AS CHAR), \'$.id\')) AS sw_order_id,
                sms_from.technical_name AS from_state,
                sms_to.technical_name AS to_state,
                smh.created_at AS transition_at
            FROM state_machine_history smh
            JOIN state_machine_state sms_from ON sms_from.id = smh.from_state_id
            JOIN state_machine_state sms_to ON sms_to.id = smh.to_state_id
            WHERE smh.entity_name = ?
            ORDER BY smh.created_at ASC
        ', ['order']);

        $grouped = [];
        foreach ($rows as $row) {
            $orderId = (string) ($row->sw_order_id ?? '');
            if ($orderId === '') {
                continue;
            }
            $grouped[$orderId][] = $row;
        }

        return $grouped;
    }
}

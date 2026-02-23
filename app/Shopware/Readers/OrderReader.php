<?php

namespace App\Shopware\Readers;

use App\Services\ShopwareDB;

class OrderReader
{
    public function __construct(protected ShopwareDB $db) {}

    public function fetchAll(): array
    {
        return $this->db->select("
            SELECT
                LOWER(HEX(o.id)) AS id,
                o.order_number,
                o.order_date_time AS order_date,
                o.amount_total AS total,
                o.amount_net AS subtotal,
                o.position_price,
                o.shipping_total,
                o.customer_comment,
                o.currency_factor,
                LOWER(HEX(o.billing_address_id)) AS billing_address_id,
                COALESCE(sms.technical_name, '') AS status,
                o.custom_fields,
                o.deep_link_code,
                o.affiliate_code,
                o.campaign_code
            FROM `order` o
            LEFT JOIN state_machine_state sms ON sms.id = o.state_id
            WHERE o.version_id = ?
            ORDER BY o.order_date_time ASC
        ", [$this->db->liveVersionIdBin()]);
    }

    public function fetchLineItems(string $orderId): array
    {
        return $this->db->select("
            SELECT
                LOWER(HEX(oli.id)) AS id,
                oli.identifier,
                oli.label AS name,
                oli.quantity,
                oli.unit_price,
                oli.total_price,
                COALESCE(oli.payload, '{}') AS payload,
                oli.type,
                LOWER(HEX(oli.product_id)) AS product_id
            FROM order_line_item oli
            WHERE oli.order_id = UNHEX(?)
              AND oli.order_version_id = ?
            ORDER BY oli.position ASC
        ", [$orderId, $this->db->liveVersionIdBin()]);
    }

    public function fetchOrderCustomer(string $orderId): ?object
    {
        $results = $this->db->select('
            SELECT
                oc.first_name,
                oc.last_name,
                oc.email,
                LOWER(HEX(oc.customer_id)) AS customer_id
            FROM order_customer oc
            WHERE oc.order_id = UNHEX(?)
              AND oc.order_version_id = ?
        ', [$orderId, $this->db->liveVersionIdBin()]);

        return $results[0] ?? null;
    }

    public function fetchAddress(string $addressId): ?object
    {
        $results = $this->db->select("
            SELECT
                LOWER(HEX(oa.id)) AS id,
                oa.first_name,
                oa.last_name,
                oa.street,
                oa.zipcode,
                oa.city,
                oa.company,
                oa.additional_address_line1 AS address_2,
                oa.phone_number AS phone,
                COALESCE(co.iso, '') AS country_iso,
                COALESCE(cs.short_code, '') AS state_code
            FROM order_address oa
            LEFT JOIN country co ON co.id = oa.country_id
            LEFT JOIN country_state cs ON cs.id = oa.country_state_id
            WHERE oa.id = UNHEX(?)
              AND oa.order_version_id = ?
        ", [$addressId, $this->db->liveVersionIdBin()]);

        return $results[0] ?? null;
    }

    public function fetchShippingAddress(string $orderId): ?object
    {
        $results = $this->db->select("
            SELECT
                LOWER(HEX(oa.id)) AS id,
                oa.first_name,
                oa.last_name,
                oa.street,
                oa.zipcode,
                oa.city,
                oa.company,
                oa.additional_address_line1 AS address_2,
                oa.phone_number AS phone,
                COALESCE(co.iso, '') AS country_iso,
                COALESCE(cs.short_code, '') AS state_code
            FROM order_delivery od
            INNER JOIN order_address oa ON oa.id = od.shipping_order_address_id
                AND oa.order_version_id = od.order_version_id
            LEFT JOIN country co ON co.id = oa.country_id
            LEFT JOIN country_state cs ON cs.id = oa.country_state_id
            WHERE od.order_id = UNHEX(?)
              AND od.order_version_id = ?
            LIMIT 1
        ", [$orderId, $this->db->liveVersionIdBin()]);

        return $results[0] ?? null;
    }

    public function fetchDeliveryTracking(string $orderId): array
    {
        $results = $this->db->select('
            SELECT
                od.tracking_codes
            FROM order_delivery od
            WHERE od.order_id = UNHEX(?)
              AND od.order_version_id = ?
              AND od.tracking_codes IS NOT NULL
            LIMIT 1
        ', [$orderId, $this->db->liveVersionIdBin()]);

        if (empty($results) || empty($results[0]->tracking_codes)) {
            return [];
        }

        $trackingCodes = is_string($results[0]->tracking_codes)
            ? json_decode($results[0]->tracking_codes, true)
            : $results[0]->tracking_codes;

        return is_array($trackingCodes) ? $trackingCodes : [];
    }
}

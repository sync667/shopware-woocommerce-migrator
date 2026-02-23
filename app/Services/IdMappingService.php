<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class IdMappingService
{
    /**
     * Get WooCommerce ID from Shopware UUID
     */
    public function getWooId(string $entityType, string $shopwareUuid): ?int
    {
        $mapping = DB::table('shopware_woocommerce_id_map')
            ->where('entity_type', $entityType)
            ->where('shopware_uuid', $shopwareUuid)
            ->first();

        return $mapping?->woocommerce_id;
    }

    /**
     * Store mapping after creating WooCommerce entity
     */
    public function storeMapping(string $entityType, string $shopwareUuid, int $wooId): void
    {
        DB::table('shopware_woocommerce_id_map')->insert([
            'entity_type' => $entityType,
            'shopware_uuid' => $shopwareUuid,
            'woocommerce_id' => $wooId,
            'created_at' => now(),
        ]);
    }

    /**
     * Get Shopware UUID from WooCommerce ID (reverse lookup)
     */
    public function getShopwareUuid(string $entityType, int $wooId): ?string
    {
        $mapping = DB::table('shopware_woocommerce_id_map')
            ->where('entity_type', $entityType)
            ->where('woocommerce_id', $wooId)
            ->first();

        return $mapping?->shopware_uuid;
    }

    /**
     * Check if mapping exists
     */
    public function mappingExists(string $entityType, string $shopwareUuid): bool
    {
        return DB::table('shopware_woocommerce_id_map')
            ->where('entity_type', $entityType)
            ->where('shopware_uuid', $shopwareUuid)
            ->exists();
    }

    /**
     * Update existing mapping (for re-migrations)
     */
    public function updateMapping(string $entityType, string $shopwareUuid, int $newWooId): void
    {
        DB::table('shopware_woocommerce_id_map')
            ->where('entity_type', $entityType)
            ->where('shopware_uuid', $shopwareUuid)
            ->update([
                'woocommerce_id' => $newWooId,
                'created_at' => now(),
            ]);
    }

    /**
     * Get or create mapping (idempotent)
     */
    public function getOrStore(string $entityType, string $shopwareUuid, int $wooId): int
    {
        $existing = $this->getWooId($entityType, $shopwareUuid);

        if ($existing) {
            return $existing;
        }

        $this->storeMapping($entityType, $shopwareUuid, $wooId);

        return $wooId;
    }
}

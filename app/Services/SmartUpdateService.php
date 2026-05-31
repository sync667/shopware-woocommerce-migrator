<?php

namespace App\Services;

use App\Models\MigrationRun;
use Carbon\Carbon;

class SmartUpdateService
{
    /**
     * Determine if record should be migrated in delta mode
     */
    public function shouldMigrate(
        string $entityType,
        string $shopwareId,
        ?Carbon $shopwareUpdatedAt,
        int $migrationId,
        StateManager $stateManager
    ): array {
        $migration = MigrationRun::find($migrationId);

        if ($migration->sync_mode === 'full') {
            return [
                'should_migrate' => true,
                'action' => 'create_or_update',
                'reason' => 'full_migration_mode',
            ];
        }

        $lastSyncAt = $migration->last_sync_at;

        if (! $lastSyncAt) {
            return [
                'should_migrate' => true,
                'action' => 'create_or_update',
                'reason' => 'first_delta_sync',
            ];
        }

        $entity = $stateManager->getEntity($entityType, $shopwareId, $migrationId);

        if (! $entity) {
            return [
                'should_migrate' => true,
                'action' => 'create',
                'reason' => 'new_record',
            ];
        }

        if (! $shopwareUpdatedAt) {
            return [
                'should_migrate' => true,
                'action' => 'update',
                'reason' => 'no_timestamp_skip_unsafe',
            ];
        }

        if ($shopwareUpdatedAt->greaterThan($lastSyncAt)) {
            return [
                'should_migrate' => true,
                'action' => 'update',
                'reason' => 'updated_in_shopware',
                'shopware_updated_at' => $shopwareUpdatedAt,
                'last_sync_at' => $lastSyncAt,
            ];
        }

        return [
            'should_migrate' => false,
            'action' => 'skip',
            'reason' => 'no_changes_since_last_sync',
            'shopware_updated_at' => $shopwareUpdatedAt,
            'last_sync_at' => $lastSyncAt,
        ];
    }

    /**
     * Detect WooCommerce-side changes (conflict detection)
     */
    public function detectConflict(
        string $entityType,
        int $wooId,
        ?Carbon $shopwareUpdatedAt,
        ?Carbon $lastSyncedAt,
        WooCommerceClient $woo
    ): array {
        $endpoint = $this->getWooEndpoint($entityType, $wooId);

        try {
            $wooRecord = $woo->get($endpoint);
            $wooUpdatedAt = Carbon::parse($wooRecord['date_modified'] ?? $wooRecord['date_created']);
        } catch (\Exception $e) {
            // WooCommerce record not found or deleted
            return [
                'has_conflict' => false,
                'reason' => 'woo_record_not_found',
            ];
        }

        if (! $lastSyncedAt) {
            // No sync record, can't detect conflict
            return [
                'has_conflict' => false,
                'reason' => 'no_sync_record',
            ];
        }

        if ($wooUpdatedAt->greaterThan($lastSyncedAt)) {
            return [
                'has_conflict' => true,
                'reason' => 'both_sides_modified',
                'shopware_updated_at' => $shopwareUpdatedAt,
                'woo_updated_at' => $wooUpdatedAt,
                'last_synced_at' => $lastSyncedAt,
            ];
        }

        return [
            'has_conflict' => false,
            'reason' => 'woo_not_modified',
        ];
    }

    /**
     * Resolve conflict based on strategy
     */
    public function resolveConflict(
        string $strategy,
        array $conflictInfo
    ): string {
        return match ($strategy) {
            'shopware_wins' => 'update',  // Overwrite WC
            'woo_wins' => 'skip',          // Keep WC
            'manual' => 'flag',            // Flag for review
            default => 'update',           // Default to Shopware wins
        };
    }

    /**
     * Get WooCommerce API endpoint for entity type
     */
    protected function getWooEndpoint(string $entityType, int $wooId): string
    {
        return match ($entityType) {
            'product' => "products/{$wooId}",
            'customer' => "customers/{$wooId}",
            'order' => "orders/{$wooId}",
            'category' => "products/categories/{$wooId}",
            'coupon' => "coupons/{$wooId}",
            default => "{$entityType}s/{$wooId}",
        };
    }
}

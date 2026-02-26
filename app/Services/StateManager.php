<?php

namespace App\Services;

use App\Models\MigrationEntity;
use Carbon\Carbon;

class StateManager
{
    public function set(string $entityType, string $shopwareId, int $wooId, int $migrationId, ?array $payload = null): void
    {
        $data = [
            'woo_id' => $wooId,
            'status' => 'success',
        ];

        if ($payload !== null) {
            $data['payload'] = $payload;
        }

        MigrationEntity::updateOrCreate(
            [
                'migration_id' => $migrationId,
                'entity_type' => $entityType,
                'shopware_id' => $shopwareId,
            ],
            $data
        );
    }

    public function get(string $entityType, string $shopwareId, int $migrationId): ?int
    {
        $entity = MigrationEntity::where('migration_id', $migrationId)
            ->where('entity_type', $entityType)
            ->where('shopware_id', $shopwareId)
            ->first();

        return $entity?->woo_id;
    }

    public function alreadyMigrated(string $entityType, string $shopwareId, int $migrationId): bool
    {
        return MigrationEntity::where('migration_id', $migrationId)
            ->where('entity_type', $entityType)
            ->where('shopware_id', $shopwareId)
            ->where('status', 'success')
            ->exists();
    }

    public function getMap(string $entityType, int $migrationId): array
    {
        return MigrationEntity::where('migration_id', $migrationId)
            ->where('entity_type', $entityType)
            ->where('status', 'success')
            ->whereNotNull('woo_id')
            ->pluck('woo_id', 'shopware_id')
            ->toArray();
    }

    /**
     * Returns a map of shopware_tax_id => wc_tax_class_slug for migrated taxes.
     * Tax class slugs are strings (e.g. "23-vat", ""), so they are stored in payload
     * rather than the integer woo_id column.
     *
     * @return array<string, string>
     */
    public function getTaxClassMap(int $migrationId): array
    {
        return MigrationEntity::where('migration_id', $migrationId)
            ->where('entity_type', 'tax')
            ->where('status', 'success')
            ->get(['shopware_id', 'payload'])
            ->mapWithKeys(fn ($e) => [$e->shopware_id => $e->payload['class_slug'] ?? ''])
            ->toArray();
    }

    public function markFailed(string $entityType, string $shopwareId, int $migrationId, string $error): void
    {
        MigrationEntity::updateOrCreate(
            [
                'migration_id' => $migrationId,
                'entity_type' => $entityType,
                'shopware_id' => $shopwareId,
            ],
            [
                'status' => 'failed',
                'error_message' => mb_scrub(substr($error, 0, 1000), 'UTF-8'),
            ]
        );
    }

    public function markPending(string $entityType, string $shopwareId, int $migrationId, ?array $payload = null): void
    {
        MigrationEntity::updateOrCreate(
            [
                'migration_id' => $migrationId,
                'entity_type' => $entityType,
                'shopware_id' => $shopwareId,
            ],
            [
                'status' => 'pending',
                'payload' => $payload,
            ]
        );
    }

    public function markSkipped(string $entityType, string $shopwareId, int $migrationId, ?array $payload = null): void
    {
        MigrationEntity::updateOrCreate(
            [
                'migration_id' => $migrationId,
                'entity_type' => $entityType,
                'shopware_id' => $shopwareId,
            ],
            [
                'status' => 'skipped',
                'payload' => $payload,
            ]
        );
    }

    /**
     * Get full entity record with metadata
     */
    public function getEntity(string $entityType, string $shopwareId, int $migrationId): ?MigrationEntity
    {
        return MigrationEntity::where('migration_id', $migrationId)
            ->where('entity_type', $entityType)
            ->where('shopware_id', $shopwareId)
            ->first();
    }

    /**
     * Update sync metadata for delta migration
     */
    public function updateSyncMetadata(
        string $entityType,
        string $shopwareId,
        int $migrationId,
        ?Carbon $shopwareUpdatedAt,
        string $syncStatus
    ): void {
        MigrationEntity::where('migration_id', $migrationId)
            ->where('entity_type', $entityType)
            ->where('shopware_id', $shopwareId)
            ->update([
                'shopware_updated_at' => $shopwareUpdatedAt,
                'last_synced_at' => now(),
                'sync_status' => $syncStatus,
            ]);
    }

    /**
     * Mark entity as having a conflict
     */
    public function markConflict(
        string $entityType,
        string $shopwareId,
        int $migrationId,
        array $conflictInfo
    ): void {
        MigrationEntity::where('migration_id', $migrationId)
            ->where('entity_type', $entityType)
            ->where('shopware_id', $shopwareId)
            ->update([
                'sync_status' => 'conflict',
                'error_message' => json_encode($conflictInfo),
            ]);
    }
}

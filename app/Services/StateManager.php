<?php

namespace App\Services;

use App\Models\MigrationEntity;

class StateManager
{
    public function set(string $entityType, string $shopwareId, int $wooId, int $migrationId): void
    {
        MigrationEntity::updateOrCreate(
            [
                'migration_id' => $migrationId,
                'entity_type' => $entityType,
                'shopware_id' => $shopwareId,
            ],
            [
                'woo_id' => $wooId,
                'status' => 'success',
            ]
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
                'error_message' => $error,
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
}

<?php

namespace App\Services;

/**
 * Detects the Shopware 6 major version by inspecting the connected database schema.
 *
 * Strategy: check for columns/tables that were added in specific versions.
 * - product.type column  → added in 6.7.0
 * - payment_method.technical_name column → added in 6.6.0
 * - Base case → 6.5
 */
class ShopwareVersionDetector
{
    public function __construct(protected ShopwareDB $db) {}

    /**
     * Detect the Shopware 6 major version line.
     *
     * @return string One of '6.7', '6.6', '6.5', or 'unknown'
     */
    public function detectMajorVersion(): string
    {
        try {
            if ($this->columnExists('product', 'type')) {
                return '6.7';
            }

            if ($this->columnExists('payment_method', 'technical_name')) {
                return '6.6';
            }

            // All Shopware 6.5+ databases have the core tables we read from
            if ($this->tableExists('product')) {
                return '6.5';
            }

            return 'unknown';
        } catch (\Exception $e) {
            return 'unknown';
        }
    }

    /**
     * Return a structured compatibility report for the connected database.
     *
     * @return array{version: string, features: array<string, bool>, warnings: string[]}
     */
    public function detect(): array
    {
        $version = $this->detectMajorVersion();

        $features = [
            'product_type_column' => $this->columnExists('product', 'type'),
            'payment_technical_name' => $this->columnExists('payment_method', 'technical_name'),
            'shipping_technical_name' => $this->columnExists('shipping_method', 'technical_name'),
            'product_states_column' => $this->columnExists('product', 'states'),
            'canonical_product_version_id' => $this->columnExists('product', 'canonical_product_version_id'),
        ];

        $warnings = [];
        if ($version === 'unknown') {
            $warnings[] = 'Could not determine Shopware version. The database may not be a Shopware 6 installation.';
        }
        if ($version === '6.5' && ! $features['payment_technical_name']) {
            $warnings[] = 'payment_method.technical_name column not found. Payment method migration will rely on handler_identifier only.';
        }

        return [
            'version' => $version,
            'features' => $features,
            'warnings' => $warnings,
        ];
    }

    /**
     * Check whether a column exists on a table.
     */
    protected function columnExists(string $table, string $column): bool
    {
        try {
            $results = $this->db->select(
                'SELECT COUNT(*) AS cnt FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$table, $column]
            );

            return ($results[0]->cnt ?? $results[0]['cnt'] ?? 0) > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Check whether a table exists in the current database.
     */
    protected function tableExists(string $table): bool
    {
        try {
            $results = $this->db->select(
                'SELECT COUNT(*) AS cnt FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                [$table]
            );

            return ($results[0]->cnt ?? $results[0]['cnt'] ?? 0) > 0;
        } catch (\Exception $e) {
            return false;
        }
    }
}

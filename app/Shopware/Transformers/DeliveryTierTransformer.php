<?php

namespace App\Shopware\Transformers;

use InvalidArgumentException;

class DeliveryTierTransformer
{
    /**
     * Extract per-product delivery tiers from a Shopware product's custom_fields JSON.
     *
     * Source field name is configurable via COMPANION_SHOPWARE_TIER_FIELD; rows
     * are {quantityFrom, quantityTo, grossPrice}. Returns null when no tiers are
     * present. Throws on any invalid row so bad data is never silently dropped.
     *
     * @return array<int, array{from: int, to: ?int, cost: float}>|null
     */
    public static function extract(object $product): ?array
    {
        $raw = $product->custom_fields ?? null;
        if ($raw === null || $raw === '' || $raw === []) {
            return null;
        }

        $payload = is_string($raw) ? json_decode($raw, true) : (array) $raw;
        if (! is_array($payload)) {
            return null;
        }

        $field = (string) config('migration.companion.shopware_tier_field');
        $rows = $payload[$field] ?? null;
        if (! is_array($rows) || $rows === []) {
            return null;
        }

        $normalized = [];
        foreach ($rows as $i => $row) {
            $normalized[] = self::validateRow($row, $i);
        }

        usort($normalized, fn ($a, $b) => $a['from'] <=> $b['from']);

        return $normalized;
    }

    /**
     * @param  mixed  $row
     * @return array{from: int, to: ?int, cost: float}
     */
    private static function validateRow($row, int $index): array
    {
        if (! is_array($row)) {
            throw new InvalidArgumentException("Tier #{$index}: expected object, got ".gettype($row));
        }

        if (! array_key_exists('quantityFrom', $row) || ! is_numeric($row['quantityFrom'])) {
            throw new InvalidArgumentException("Tier #{$index}: missing or non-numeric 'quantityFrom'");
        }
        $from = (int) $row['quantityFrom'];
        if ($from < 1) {
            throw new InvalidArgumentException("Tier #{$index}: 'from' must be >= 1, got {$from}");
        }

        $to = null;
        if (array_key_exists('quantityTo', $row) && $row['quantityTo'] !== null) {
            if (! is_numeric($row['quantityTo'])) {
                throw new InvalidArgumentException("Tier #{$index}: 'to' must be int|null, got ".gettype($row['quantityTo']));
            }
            $to = (int) $row['quantityTo'];
            if ($to < $from) {
                throw new InvalidArgumentException("Tier #{$index}: 'to' ({$to}) must be >= 'from' ({$from})");
            }
        }

        if (! array_key_exists('grossPrice', $row) || ! is_numeric($row['grossPrice'])) {
            throw new InvalidArgumentException("Tier #{$index}: missing or non-numeric 'grossPrice'");
        }
        $cost = round((float) $row['grossPrice'], 2);
        if ($cost < 0) {
            throw new InvalidArgumentException("Tier #{$index}: 'cost' must be >= 0, got {$cost}");
        }

        return ['from' => $from, 'to' => $to, 'cost' => $cost];
    }
}

<?php

namespace App\Shopware\Transformers;

use InvalidArgumentException;

class DeliveryTierTransformer
{
    /**
     * Extract and validate the RemizaSklep delivery-tier list out of a Shopware
     * product's custom_fields JSON.
     *
     * Source shape (Shopware admin "Progi wysyłki" custom field):
     *   "remiza_shipping_tiers": [
     *     {"quantityFrom": 1, "quantityTo": 1,    "grossPrice": 120},
     *     {"quantityFrom": 2, "quantityTo": null, "grossPrice": 240}
     *   ]
     *
     * Target shape (RemizaSklep plugin contract):
     *   [{from: int, to: int|null, cost: float}]
     *
     * Returns null when no tier override is set on the product. Throws on any
     * row that violates the plugin's read-time validation rules — per the spec
     * the migrator must "fail loud instead" of silently dropping bad rows.
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

        $rows = $payload['remiza_shipping_tiers'] ?? null;
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

<?php

namespace App\Jobs;

use App\Models\MigrationEntity;
use App\Models\MigrationLog;
use App\Models\MigrationRun;
use App\Services\ShopwareDB;
use App\Services\WooCommerceDB;
use App\Shopware\Readers\OrderHistoryReader;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class MigrateOrderHistoryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 5;

    public int $timeout = 3600;

    public function __construct(protected int $migrationId)
    {
        $this->onQueue('migration');
    }

    public function handle(): void
    {
        $migration = MigrationRun::findOrFail($this->migrationId);

        if ($migration->is_dry_run) {
            $this->log('info', 'Order status history: skipped (dry run).');

            return;
        }

        $woocommerceDb = WooCommerceDB::fromMigration($migration);
        if (! $woocommerceDb->isConfigured()) {
            $this->log('info', 'Order status history: skipped (WC DB credentials not set).');

            return;
        }

        $orderMap = MigrationEntity::where('migration_id', $this->migrationId)
            ->where('entity_type', 'order')
            ->whereIn('status', ['success', 'skipped'])
            ->pluck('woo_id', 'shopware_id')
            ->filter()
            ->all();

        if ($orderMap === []) {
            $this->log('info', 'Order status history: no migrated orders to annotate.');

            return;
        }

        $db = ShopwareDB::fromMigration($migration);

        try {
            $reader = new OrderHistoryReader($db);
            $history = $reader->fetchAllByOrder();

            $notes = self::buildNotes($history, $orderMap);

            if ($notes === []) {
                $this->log('info', 'Order status history: no transitions matched migrated orders.');

                return;
            }

            $inserted = $woocommerceDb->bulkInsertOrderNotes($notes);

            $this->log('info', "Order status history: wrote {$inserted} note(s) across ".count($orderMap).' migrated order(s).');
        } catch (\Throwable $e) {
            $this->log('warning', "Order status history failed: {$e->getMessage()}");
        } finally {
            $db->disconnect();
            $woocommerceDb->disconnect();
        }
    }

    /**
     * Pure transform: state history grouped by Shopware id + order map → WC note rows.
     * Extracted so the unit tests can exercise it without touching either database.
     *
     * @param  array<string, array<int, object>>  $historyBySwId
     * @param  array<string, int>  $orderMap  shopware_id => woo_id
     * @return array<int, array{order_id:int, content:string, transition_at:string}>
     */
    public static function buildNotes(array $historyBySwId, array $orderMap): array
    {
        $notes = [];

        foreach ($historyBySwId as $swOrderId => $transitions) {
            $wooId = $orderMap[$swOrderId] ?? null;
            if (! $wooId) {
                continue;
            }

            foreach ($transitions as $t) {
                $from = (string) ($t->from_state ?? '');
                $to = (string) ($t->to_state ?? '');
                $at = (string) ($t->transition_at ?? '');
                if ($from === '' || $to === '' || $at === '') {
                    continue;
                }
                $notes[] = [
                    'order_id' => (int) $wooId,
                    'content' => "Shopware status: {$from} → {$to}",
                    'transition_at' => $at,
                ];
            }
        }

        return $notes;
    }

    protected function log(string $level, string $message): void
    {
        MigrationLog::create([
            'migration_id' => $this->migrationId,
            'entity_type' => 'order',
            'level' => $level,
            'message' => $message,
            'created_at' => now(),
        ]);
    }
}

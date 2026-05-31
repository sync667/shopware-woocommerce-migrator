<?php

namespace App\Jobs;

use App\Models\MigrationLog;
use App\Models\MigrationRun;
use App\Services\CancellationService;
use App\Services\ShopwareDB;
use App\Shopware\Readers\CustomerWishlistReader;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Optional wishlist export. WooCommerce core has no wishlist; the most common targets
 * (YITH WooCommerce Wishlist, TI WooCommerce Wishlist, etc.) each use a different
 * storage scheme, so this job writes a CSV at storage/app/migrations/{id}/wishlists.csv
 * with one row per (customer, product) pair. The operator imports into whichever
 * plugin the new shop uses. Dispatched only when `wishlist_options.enabled` is set.
 */
class MigrateCustomerWishlistsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 5;

    public int $timeout = 1800;

    public function __construct(protected int $migrationId) {}

    public function handle(CancellationService $cancellation): void
    {
        $migration = MigrationRun::findOrFail($this->migrationId);

        if ($cancellation->isCancelled($this->migrationId)) {
            return;
        }

        $reader = $this->makeReader($migration);
        $handle = $this->openCsv();
        $count = 0;

        try {
            foreach ($reader->stream() as $row) {
                if ($cancellation->isCancelled($this->migrationId)) {
                    return;
                }

                fputcsv($handle, [
                    $row->wishlist_product_id ?? '',
                    $row->wishlist_id ?? '',
                    $row->customer_id ?? '',
                    $row->customer_email ?? '',
                    $row->product_id ?? '',
                    $row->sku ?? '',
                    $row->added_at ?? '',
                ]);
                $count++;
            }
        } finally {
            fclose($handle);
        }

        MigrationLog::create([
            'migration_id' => $this->migrationId,
            'entity_type' => 'customer_wishlist',
            'level' => 'info',
            'message' => "Exported {$count} wishlist entry/entries to CSV (import into YITH or TI WooCommerce Wishlist).",
            'created_at' => now(),
        ]);
    }

    protected function makeReader(MigrationRun $migration): CustomerWishlistReader
    {
        $db = ShopwareDB::fromMigration($migration);

        return app(CustomerWishlistReader::class, ['db' => $db]);
    }

    /**
     * @return resource
     */
    protected function openCsv()
    {
        $dir = storage_path("app/migrations/{$this->migrationId}");
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $path = $dir.'/wishlists.csv';

        $isNew = ! file_exists($path) || filesize($path) === 0;
        $handle = fopen($path, 'a');
        if ($handle === false) {
            throw new \RuntimeException("Unable to open CSV at {$path}");
        }

        if ($isNew) {
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, [
                'wishlist_product_id',
                'wishlist_id',
                'customer_id',
                'customer_email',
                'product_id',
                'sku',
                'added_at',
            ]);
        }

        return $handle;
    }
}

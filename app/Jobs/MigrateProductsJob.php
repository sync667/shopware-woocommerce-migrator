<?php

namespace App\Jobs;

use App\Models\MigrationLog;
use App\Models\MigrationRun;
use App\Services\ShopwareDB;
use App\Shopware\Readers\ProductReader;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class MigrateProductsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 5;

    public function __construct(protected int $migrationId) {}

    public function handle(): void
    {
        $migration = MigrationRun::findOrFail($this->migrationId);
        $db = ShopwareDB::fromMigration($migration);
        $reader = new ProductReader($db);

        $products = $reader->fetchAllParents();

        MigrationLog::create([
            'migration_id' => $this->migrationId,
            'entity_type' => 'product',
            'level' => 'info',
            'message' => 'Dispatching '.count($products).' product migration jobs',
            'created_at' => now(),
        ]);

        foreach ($products as $product) {
            MigrateProductJob::dispatch($this->migrationId, $product->id)
                ->onQueue('products');
        }
    }
}

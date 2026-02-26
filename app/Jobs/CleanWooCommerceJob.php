<?php

namespace App\Jobs;

use App\Models\MigrationRun;
use App\Services\WooCommerceCleanup;
use App\Services\WooCommerceClient;
use App\Services\WordPressMediaClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CleanWooCommerceJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 7200; // 2 hours — media + product cleanup can take a long time

    public function __construct(
        public int $migrationId,
        public string $entity
    ) {
        $this->onQueue('heavy');
    }

    public function handle(): void
    {
        $migration = MigrationRun::findOrFail($this->migrationId);
        $wooClient = WooCommerceClient::fromMigration($migration);
        $wpClient = WordPressMediaClient::fromMigration($migration);

        $cleanup = new WooCommerceCleanup($wooClient, $this->migrationId, $wpClient, $migration);
        $cleanup->cleanEntity($this->entity);
    }
}

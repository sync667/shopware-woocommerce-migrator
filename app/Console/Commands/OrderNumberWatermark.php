<?php

namespace App\Console\Commands;

use App\Models\MigrationRun;
use App\Services\WooCommerceDB;
use Illuminate\Console\Command;

class OrderNumberWatermark extends Command
{
    protected $signature = 'migration:order-number-watermark
        {migrationId : MigrationRun id whose WC DB credentials to use}
        {--bump : Apply the AUTO_INCREMENT bump (default is report-only)}';

    protected $description = 'Report MAX(_order_number) and optionally bump wp_posts.AUTO_INCREMENT past it.';

    public function handle(): int
    {
        $migration = MigrationRun::find((int) $this->argument('migrationId'));

        if (! $migration) {
            $this->error('MigrationRun #'.$this->argument('migrationId').' not found.');

            return self::FAILURE;
        }

        $db = WooCommerceDB::fromMigration($migration);

        if (! $db->isConfigured()) {
            $this->error('WooCommerce DB credentials not set on this migration.');

            return self::FAILURE;
        }

        $highest = $db->highestStampedOrderNumber();

        if ($highest === null) {
            $this->warn('No `_order_number` meta found — no orders migrated yet.');

            return self::SUCCESS;
        }

        $next = $highest + 1;
        $this->info("Highest migrated _order_number: {$highest}");
        $this->info("Next new order would be: #{$next}");

        if (! $this->option('bump')) {
            $this->line('Pass --bump to apply the AUTO_INCREMENT bump.');

            return self::SUCCESS;
        }

        $bumped = $db->bumpPostsAutoIncrementTo($next);

        $this->info($bumped
            ? "Bumped wp_posts.AUTO_INCREMENT to {$next}."
            : "wp_posts.AUTO_INCREMENT already above {$next}, left unchanged.");

        return self::SUCCESS;
    }
}

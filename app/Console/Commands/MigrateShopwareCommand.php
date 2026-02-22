<?php

namespace App\Console\Commands;

use App\Jobs\MigrateCategoriesJob;
use App\Jobs\MigrateCouponsJob;
use App\Jobs\MigrateCustomersJob;
use App\Jobs\MigrateManufacturersJob;
use App\Jobs\MigrateOrdersJob;
use App\Jobs\MigrateProductsJob;
use App\Jobs\MigrateReviewsJob;
use App\Jobs\MigrateTaxesJob;
use App\Models\MigrationRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

class MigrateShopwareCommand extends Command
{
    protected $signature = 'shopware:migrate
        {--name=CLI Migration : Migration run name}
        {--dry-run : Run without writing to WooCommerce}
        {--sw-host= : Shopware DB host}
        {--sw-port=3306 : Shopware DB port}
        {--sw-database= : Shopware DB database}
        {--sw-username= : Shopware DB username}
        {--sw-password= : Shopware DB password}
        {--sw-language-id= : Shopware language ID (hex)}
        {--sw-version-id= : Shopware live version ID (hex)}
        {--sw-base-url= : Shopware base URL}
        {--wc-base-url= : WooCommerce base URL}
        {--wc-key= : WooCommerce consumer key}
        {--wc-secret= : WooCommerce consumer secret}
        {--wp-username= : WordPress username}
        {--wp-app-password= : WordPress application password}';

    protected $description = 'Run Shopware → WooCommerce migration via CLI';

    public function handle(): int
    {
        $settings = [
            'shopware' => [
                'db_host' => $this->option('sw-host') ?: config('shopware.db.host'),
                'db_port' => (int) ($this->option('sw-port') ?: config('shopware.db.port', 3306)),
                'db_database' => $this->option('sw-database') ?: config('shopware.db.database'),
                'db_username' => $this->option('sw-username') ?: config('shopware.db.username'),
                'db_password' => $this->option('sw-password') ?: config('shopware.db.password'),
                'language_id' => $this->option('sw-language-id') ?: config('shopware.language_id'),
                'live_version_id' => $this->option('sw-version-id') ?: config('shopware.live_version_id'),
                'base_url' => $this->option('sw-base-url') ?: config('shopware.base_url'),
            ],
            'woocommerce' => [
                'base_url' => $this->option('wc-base-url') ?: env('WOO_BASE_URL', ''),
                'consumer_key' => $this->option('wc-key') ?: env('WOO_CONSUMER_KEY', ''),
                'consumer_secret' => $this->option('wc-secret') ?: env('WOO_CONSUMER_SECRET', ''),
            ],
            'wordpress' => [
                'username' => $this->option('wp-username') ?: env('WP_USERNAME', ''),
                'app_password' => $this->option('wp-app-password') ?: env('WP_APP_PASSWORD', ''),
            ],
        ];

        $migration = MigrationRun::create([
            'name' => $this->option('name'),
            'settings' => $settings,
            'is_dry_run' => (bool) $this->option('dry-run'),
            'status' => 'pending',
        ]);

        $this->info("Migration #{$migration->id} created: {$migration->name}");

        if ($migration->is_dry_run) {
            $this->warn('Running in DRY RUN mode — no data will be written to WooCommerce.');
        }

        $migration->markRunning();

        Bus::chain([
            new MigrateManufacturersJob($migration->id),
            new MigrateTaxesJob($migration->id),
            new MigrateCategoriesJob($migration->id),
            new MigrateProductsJob($migration->id),
            new MigrateCustomersJob($migration->id),
            new MigrateOrdersJob($migration->id),
            new MigrateCouponsJob($migration->id),
            new MigrateReviewsJob($migration->id),
            function () use ($migration) {
                $migration->refresh();
                if ($migration->status === 'running') {
                    $migration->markCompleted();
                }
            },
        ])->catch(function (\Throwable $e) use ($migration) {
            $migration->markFailed();
        })->dispatch();

        $this->info('Migration jobs dispatched. Monitor progress at: /migrations/'.$migration->id);
        $this->info('Or check status via: GET /api/migrations/'.$migration->id.'/status');

        return Command::SUCCESS;
    }
}

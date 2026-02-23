<?php

namespace App\Console\Commands;

use App\Jobs\MigrateCategoriesJob;
use App\Jobs\MigrateCmsPagesJob;
use App\Jobs\MigrateCouponsJob;
use App\Jobs\MigrateCustomersJob;
use App\Jobs\MigrateManufacturersJob;
use App\Jobs\MigrateOrdersJob;
use App\Jobs\MigratePaymentMethodsJob;
use App\Jobs\MigrateProductsJob;
use App\Jobs\MigrateReviewsJob;
use App\Jobs\MigrateSeoUrlsJob;
use App\Jobs\MigrateShippingMethodsJob;
use App\Jobs\MigrateTaxesJob;
use App\Models\MigrationRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

class MigrateShopwareCommand extends Command
{
    protected $signature = 'shopware:migrate
        {--name=CLI Migration : Migration run name}
        {--dry-run : Run without writing to WooCommerce}
        {--mode=full : Migration mode: full or delta}
        {--conflict=shopware_wins : Conflict resolution strategy: shopware_wins, woo_wins, manual}
        {--skip-tests : Skip connection tests before migration}
        {--sw-host= : Shopware DB host}
        {--sw-port=3306 : Shopware DB port}
        {--sw-database= : Shopware DB database}
        {--sw-username= : Shopware DB username}
        {--sw-password= : Shopware DB password}
        {--sw-language-id= : Shopware language ID (hex)}
        {--sw-version-id= : Shopware live version ID (hex)}
        {--sw-base-url= : Shopware base URL}
        {--ssh-host= : SSH tunnel host}
        {--ssh-port=22 : SSH tunnel port}
        {--ssh-username= : SSH username}
        {--ssh-password= : SSH password}
        {--ssh-key= : Path to SSH private key}
        {--wc-base-url= : WooCommerce base URL}
        {--wc-key= : WooCommerce consumer key}
        {--wc-secret= : WooCommerce consumer secret}
        {--wp-username= : WordPress username}
        {--wp-app-password= : WordPress application password}
        {--cms-all : Migrate all CMS pages}
        {--cms-ids= : Migrate specific CMS pages by ID (comma-separated)}';

    protected $description = 'Run Shopware → WooCommerce migration via CLI';

    public function handle(): int
    {
        // Validate mode
        $mode = $this->option('mode');
        if (! in_array($mode, ['full', 'delta'])) {
            $this->error("Invalid mode: {$mode}. Must be 'full' or 'delta'");

            return Command::FAILURE;
        }

        // Validate conflict strategy
        $conflictStrategy = $this->option('conflict');
        if (! in_array($conflictStrategy, ['shopware_wins', 'woo_wins', 'manual'])) {
            $this->error("Invalid conflict strategy: {$conflictStrategy}");

            return Command::FAILURE;
        }

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

        // Add SSH configuration if provided
        if ($this->option('ssh-host')) {
            $settings['shopware']['ssh'] = [
                'host' => $this->option('ssh-host'),
                'port' => (int) ($this->option('ssh-port') ?: 22),
                'username' => $this->option('ssh-username'),
                'password' => $this->option('ssh-password'),
                'key' => $this->option('ssh-key'),
            ];
        }

        // Test connections before migration (unless skipped)
        if (! $this->option('skip-tests')) {
            $this->info('🔍 Testing connections...');
            if (! $this->testConnections($settings)) {
                $this->error('Connection tests failed. Migration aborted.');
                $this->line('Use --skip-tests to bypass connection testing.');

                return Command::FAILURE;
            }
            $this->newLine();
        }

        $migration = MigrationRun::create([
            'name' => $this->option('name'),
            'settings' => $settings,
            'is_dry_run' => (bool) $this->option('dry-run'),
            'sync_mode' => $mode,
            'conflict_strategy' => $conflictStrategy,
            'status' => 'pending',
        ]);

        $this->info("Migration #{$migration->id} created: {$migration->name}");

        if ($migration->is_dry_run) {
            $this->warn('Running in DRY RUN mode — no data will be written to WooCommerce.');
        }

        // Display migration mode information
        if ($mode === 'delta') {
            $this->info('🔄 Running DELTA migration (only changed records)');
            $this->info("⚔️  Conflict strategy: {$conflictStrategy}");
        } else {
            $this->info('🔄 Running FULL migration (all records)');
        }

        $migration->markRunning();

        // Build job chain
        $jobs = [
            new MigrateManufacturersJob($migration->id),
            new MigrateTaxesJob($migration->id),
            new MigrateCategoriesJob($migration->id),
            new MigrateProductsJob($migration->id),
            new MigrateCustomersJob($migration->id),
            new MigrateOrdersJob($migration->id),
            new MigrateCouponsJob($migration->id),
            new MigrateReviewsJob($migration->id),
            new MigrateShippingMethodsJob($migration->id),
            new MigratePaymentMethodsJob($migration->id),
            new MigrateSeoUrlsJob($migration->id),
        ];

        // Add CMS pages migration if requested
        if ($this->option('cms-all')) {
            $jobs[] = new MigrateCmsPagesJob($migration->id);
            $this->info('CMS pages: Migrating all pages');
        } elseif ($cmsIds = $this->option('cms-ids')) {
            $ids = array_map('trim', explode(',', $cmsIds));
            $jobs[] = new MigrateCmsPagesJob($migration->id, $ids);
            $this->info('CMS pages: Migrating '.count($ids).' selected page(s)');
        }

        // Add completion handler
        $jobs[] = function () use ($migration) {
            $migration->refresh();
            if ($migration->status === 'running') {
                $migration->markCompleted();
            }
        };

        Bus::chain($jobs)->catch(function (\Throwable $e) use ($migration) {
            $migration->markFailed();
        })->dispatch();

        $this->info('Migration jobs dispatched. Monitor progress at: /migrations/'.$migration->id);
        $this->info('Or check status via: GET /api/migrations/'.$migration->id.'/status');

        return Command::SUCCESS;
    }

    /**
     * Test connections before migration
     */
    protected function testConnections(array $settings): bool
    {
        $allPassed = true;

        // Test Shopware DB
        try {
            $db = new \App\Services\ShopwareDB($settings['shopware']);
            $result = $db->select('SELECT VERSION() as version');
            $this->line('<fg=green>✓</> Shopware DB: Connected ('.$result[0]->version.')');
        } catch (\Exception $e) {
            $this->error('✗ Shopware DB: '.$e->getMessage());
            $allPassed = false;
        }

        // Test WooCommerce API (unless dry-run)
        if (! $this->option('dry-run')) {
            try {
                $woo = new \App\Services\WooCommerceClient($settings['woocommerce']);
                $woo->get('system_status');
                $this->line('<fg=green>✓</> WooCommerce API: Connected');
            } catch (\Exception $e) {
                $this->error('✗ WooCommerce API: '.$e->getMessage());
                $allPassed = false;
            }
        }

        return $allPassed;
    }
}

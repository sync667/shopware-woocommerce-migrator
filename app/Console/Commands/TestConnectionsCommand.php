<?php

namespace App\Console\Commands;

use App\Services\ShopwareDB;
use App\Services\WooCommerceClient;
use App\Services\WordPressMediaClient;
use Illuminate\Console\Command;

class TestConnectionsCommand extends Command
{
    protected $signature = 'shopware:test-connections
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
        {--wp-app-password= : WordPress application password}';

    protected $description = 'Test all connections (Shopware DB, WooCommerce API, WordPress)';

    public function handle(): int
    {
        $this->info('🔍 Testing Connections...');
        $this->newLine();

        $allPassed = true;

        // Test Shopware Database
        if ($this->hasShopwareConfig()) {
            $allPassed = $this->testShopwareConnection() && $allPassed;
        } else {
            $this->warn('⚠️  Shopware configuration not provided, skipping DB test');
        }

        $this->newLine();

        // Test WooCommerce API
        if ($this->hasWooCommerceConfig()) {
            $allPassed = $this->testWooCommerceConnection() && $allPassed;
        } else {
            $this->warn('⚠️  WooCommerce configuration not provided, skipping API test');
        }

        $this->newLine();

        // Test WordPress Media
        if ($this->hasWordPressConfig()) {
            $allPassed = $this->testWordPressConnection() && $allPassed;
        } else {
            $this->warn('⚠️  WordPress configuration not provided, skipping media test');
        }

        $this->newLine();

        if ($allPassed) {
            $this->info('✅ All connection tests passed!');

            return self::SUCCESS;
        }

        $this->error('❌ Some connection tests failed. Please check the errors above.');

        return self::FAILURE;
    }

    protected function testShopwareConnection(): bool
    {
        $this->line('<fg=cyan>Testing Shopware Database Connection...</>');

        try {
            $config = [
                'host' => $this->option('sw-host') ?: config('shopware.db.host'),
                'port' => (int) ($this->option('sw-port') ?: config('shopware.db.port', 3306)),
                'database' => $this->option('sw-database') ?: config('shopware.db.database'),
                'username' => $this->option('sw-username') ?: config('shopware.db.username'),
                'password' => $this->option('sw-password') ?: config('shopware.db.password'),
                'language_id' => $this->option('sw-language-id') ?: config('shopware.language_id'),
                'live_version_id' => $this->option('sw-version-id') ?: config('shopware.live_version_id'),
            ];

            // Add SSH tunnel if configured
            if ($this->hasSSHConfig()) {
                $config['ssh'] = [
                    'host' => $this->option('ssh-host'),
                    'port' => (int) ($this->option('ssh-port') ?: 22),
                    'username' => $this->option('ssh-username'),
                    'password' => $this->option('ssh-password'),
                    'key' => $this->option('ssh-key'),
                ];
            }

            $db = new ShopwareDB($config);

            // Test basic connection
            $result = $db->select('SELECT VERSION() as version, DATABASE() as db_name');
            $this->line('<fg=green>  ✓</> Connected to MySQL: '.$result[0]->version);
            $this->line('<fg=green>  ✓</> Database: '.$result[0]->db_name);

            // Test if required tables exist
            $tables = ['product', 'category', 'customer', 'order'];
            foreach ($tables as $table) {
                $exists = $db->select("SHOW TABLES LIKE '{$table}'");
                if (empty($exists)) {
                    $this->error("  ✗ Required table '{$table}' not found");

                    return false;
                }
            }
            $this->line('<fg=green>  ✓</> Required tables exist');

            // Test language ID
            if ($config['language_id']) {
                $lang = $db->select('SELECT LOWER(HEX(id)) as id, name FROM language WHERE id = UNHEX(?)', [$config['language_id']]);
                if (! empty($lang)) {
                    $this->line('<fg=green>  ✓</> Language ID valid: '.$lang[0]->name);
                } else {
                    $this->error('  ✗ Language ID not found in database');

                    return false;
                }
            }

            // Count products
            $count = $db->select('SELECT COUNT(*) as count FROM product WHERE parent_id IS NULL');
            $this->line('<fg=green>  ✓</> Found '.$count[0]->count.' parent products');

            $this->info('✅ Shopware Database: OK');

            return true;
        } catch (\Exception $e) {
            $this->error('❌ Shopware Database: FAILED');
            $this->line('<fg=red>  Error:</> '.$e->getMessage());

            return false;
        }
    }

    protected function testWooCommerceConnection(): bool
    {
        $this->line('<fg=cyan>Testing WooCommerce API Connection...</>');

        try {
            $config = [
                'base_url' => $this->option('wc-base-url') ?: env('WOO_BASE_URL', ''),
                'consumer_key' => $this->option('wc-key') ?: env('WOO_CONSUMER_KEY', ''),
                'consumer_secret' => $this->option('wc-secret') ?: env('WOO_CONSUMER_SECRET', ''),
            ];

            $woo = new WooCommerceClient($config);

            // Test API connection
            $systemStatus = $woo->get('system_status');
            $this->line('<fg=green>  ✓</> Connected to WooCommerce API');

            // Get WooCommerce version
            if (isset($systemStatus['environment']['version'])) {
                $this->line('<fg=green>  ✓</> WooCommerce version: '.$systemStatus['environment']['version']);
            }

            // Test products endpoint
            $products = $woo->get('products', ['per_page' => 1]);
            $this->line('<fg=green>  ✓</> Products endpoint accessible');

            // Test categories endpoint
            $categories = $woo->get('products/categories', ['per_page' => 1]);
            $this->line('<fg=green>  ✓</> Categories endpoint accessible');

            $this->info('✅ WooCommerce API: OK');

            return true;
        } catch (\Exception $e) {
            $this->error('❌ WooCommerce API: FAILED');
            $this->line('<fg=red>  Error:</> '.$e->getMessage());
            $this->line('<fg=yellow>  Hint:</> Check your consumer key and secret');

            return false;
        }
    }

    protected function testWordPressConnection(): bool
    {
        $this->line('<fg=cyan>Testing WordPress Media API Connection...</>');

        try {
            $config = [
                'base_url' => $this->option('wc-base-url') ?: env('WOO_BASE_URL', ''),
                'wp_username' => $this->option('wp-username') ?: env('WP_USERNAME', ''),
                'wp_app_password' => $this->option('wp-app-password') ?: env('WP_APP_PASSWORD', ''),
            ];

            $wpMedia = new WordPressMediaClient($config);

            // Test by getting media list
            $testContent = 'Test connection from Shopware migrator';
            $testFilename = 'test-connection-'.time().'.txt';

            // Try to upload a test file
            $mediaId = $wpMedia->upload($testContent, $testFilename, 'text/plain', 'Test Upload', 'Connection test');

            if ($mediaId) {
                $this->line('<fg=green>  ✓</> Connected to WordPress Media API');
                $this->line('<fg=green>  ✓</> Test upload successful (ID: '.$mediaId.')');
                $this->line('<fg=yellow>  ℹ</> You may want to delete the test file from your media library');

                $this->info('✅ WordPress Media: OK');

                return true;
            }

            $this->error('❌ WordPress Media: FAILED (could not upload test file)');

            return false;
        } catch (\Exception $e) {
            $this->error('❌ WordPress Media: FAILED');
            $this->line('<fg=red>  Error:</> '.$e->getMessage());
            $this->line('<fg=yellow>  Hint:</> Check your WordPress username and application password');

            return false;
        }
    }

    protected function hasShopwareConfig(): bool
    {
        return ($this->option('sw-host') || config('shopware.db.host'))
            && ($this->option('sw-database') || config('shopware.db.database'));
    }

    protected function hasWooCommerceConfig(): bool
    {
        return ($this->option('wc-base-url') || env('WOO_BASE_URL'))
            && ($this->option('wc-key') || env('WOO_CONSUMER_KEY'));
    }

    protected function hasWordPressConfig(): bool
    {
        return ($this->option('wc-base-url') || env('WOO_BASE_URL'))
            && ($this->option('wp-username') || env('WP_USERNAME'))
            && ($this->option('wp-app-password') || env('WP_APP_PASSWORD'));
    }

    protected function hasSSHConfig(): bool
    {
        return ! empty($this->option('ssh-host'))
            && ! empty($this->option('ssh-username'));
    }
}

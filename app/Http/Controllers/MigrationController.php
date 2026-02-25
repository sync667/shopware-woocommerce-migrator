<?php

namespace App\Http\Controllers;

use App\Jobs\MigrateCategoriesJob;
use App\Jobs\MigrateManufacturersJob;
use App\Jobs\MigrateProductsJob;
use App\Jobs\MigrateTaxesJob;
use App\Models\MigrationRun;
use App\Services\ShopwareDB;
use App\Services\WooCommerceClient;
use App\Services\WordPressMediaClient;
use App\Shopware\Readers\CmsPageReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class MigrationController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'is_dry_run' => 'boolean',
            'clean_woocommerce' => 'boolean',
            'sync_mode' => 'nullable|string|in:full,delta',
            'conflict_strategy' => 'nullable|string|in:shopware_wins,woo_wins,manual',
            'cms_options' => 'nullable|array',
            'cms_options.migrate_all' => 'nullable|boolean',
            'cms_options.selected_ids' => 'nullable|array',
            'settings' => 'required|array',
            'settings.shopware' => 'required|array',
            'settings.shopware.db_host' => 'required|string',
            'settings.shopware.db_port' => 'required|integer|min:1|max:65535',
            'settings.shopware.db_database' => 'required|string',
            'settings.shopware.db_username' => 'required|string',
            'settings.shopware.db_password' => 'required|string',
            'settings.shopware.language_id' => 'required|string',
            'settings.shopware.live_version_id' => 'required|string',
            'settings.shopware.base_url' => 'required|string|url|starts_with:https://',
            'settings.shopware.ssh' => 'nullable|array',
            'settings.shopware.ssh.host' => 'required_with:settings.shopware.ssh|string',
            'settings.shopware.ssh.port' => 'nullable|integer',
            'settings.shopware.ssh.username' => 'required_with:settings.shopware.ssh|string',
            'settings.shopware.ssh.password' => 'nullable|string',
            'settings.shopware.ssh.key' => 'nullable|string',
            'settings.woocommerce' => 'required|array',
            'settings.woocommerce.base_url' => 'required|string|url|starts_with:https://',
            'settings.woocommerce.consumer_key' => 'required|string',
            'settings.woocommerce.consumer_secret' => 'required|string',
            'settings.wordpress' => 'required|array',
            'settings.wordpress.username' => 'required|string',
            'settings.wordpress.app_password' => 'required|string',
            'settings.wordpress.custom_headers' => 'nullable|array',
            'settings.wordpress.custom_headers.*' => 'nullable|string',
        ]);

        $migration = MigrationRun::create([
            'name' => $validated['name'],
            'settings' => array_merge($validated['settings'], [
                'cms_options' => $validated['cms_options'] ?? [],
            ]),
            'is_dry_run' => $validated['is_dry_run'] ?? false,
            'clean_woocommerce' => $validated['clean_woocommerce'] ?? false,
            'sync_mode' => $validated['sync_mode'] ?? 'full',
            'conflict_strategy' => $validated['conflict_strategy'] ?? 'shopware_wins',
            'status' => 'pending',
        ]);

        $migration->markRunning();

        $jobs = [];

        // Clean WooCommerce if requested (and not dry run)
        if (($validated['clean_woocommerce'] ?? false) && ! ($validated['is_dry_run'] ?? false)) {
            $jobs[] = function () use ($migration) {
                $wooClient = new \App\Services\WooCommerceClient($migration->settings['woocommerce']);
                $cleanup = new \App\Services\WooCommerceCleanup($wooClient, $migration->id);
                $results = $cleanup->cleanAll();

                \App\Models\MigrationLog::create([
                    'migration_id' => $migration->id,
                    'entity_type' => 'cleanup',
                    'level' => 'info',
                    'message' => 'WooCommerce cleanup completed: '.json_encode($results),
                    'created_at' => now(),
                ]);
            };
        }

        // Products batch dispatches customers batch via then(),
        // which in turn dispatches orders → coupons → reviews → [cms] → completion.
        $jobs = array_merge($jobs, [
            new MigrateManufacturersJob($migration->id),
            new MigrateTaxesJob($migration->id),
            new MigrateCategoriesJob($migration->id),
            new MigrateProductsJob($migration->id),
        ]);

        Bus::chain($jobs)->catch(function (\Throwable $e) use ($migration) {
            $migration->markFailed();
        })->dispatch();

        return response()->json([
            'message' => 'Migration started',
            'migration' => [
                'id' => $migration->id,
                'name' => $migration->name,
                'status' => $migration->status,
                'is_dry_run' => $migration->is_dry_run,
            ],
        ], 201);
    }

    public function status(MigrationRun $migration): JsonResponse
    {
        $counts = $migration->entities()
            ->selectRaw('entity_type, status, COUNT(*) as count')
            ->groupBy('entity_type', 'status')
            ->get()
            ->groupBy('entity_type')
            ->map(fn ($group) => $group->pluck('count', 'status')->toArray());

        $recentErrors = $migration->logs()
            ->where('level', 'error')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get(['entity_type', 'shopware_id', 'message', 'created_at']);

        $recentWarnings = $migration->logs()
            ->where('level', 'warning')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get(['entity_type', 'shopware_id', 'message', 'created_at']);

        // Summary stats
        $totalSuccess = 0;
        $totalFailed = 0;
        $totalPending = 0;
        $totalRunning = 0;
        $totalSkipped = 0;

        foreach ($counts as $entityCounts) {
            $totalSuccess += $entityCounts['success'] ?? 0;
            $totalFailed += $entityCounts['failed'] ?? 0;
            $totalPending += $entityCounts['pending'] ?? 0;
            $totalRunning += $entityCounts['running'] ?? 0;
            $totalSkipped += $entityCounts['skipped'] ?? 0;
        }

        $totalAll = $totalSuccess + $totalFailed + $totalPending + $totalRunning + $totalSkipped;

        // Elapsed time and ETA
        $elapsedSeconds = null;
        $etaSeconds = null;
        if ($migration->started_at) {
            $endTime = $migration->finished_at ?? now();
            $elapsedSeconds = (int) $migration->started_at->diffInSeconds($endTime);

            $totalProcessed = $totalSuccess + $totalSkipped;
            if ($totalProcessed > 0 && $totalPending + $totalRunning > 0 && $elapsedSeconds > 0) {
                $rate = $totalProcessed / $elapsedSeconds;
                $etaSeconds = $rate > 0 ? (int) ceil(($totalPending + $totalRunning) / $rate) : null;
            }
        }

        // Current step determination
        $stepOrder = ['manufacturer', 'tax', 'category', 'product', 'variation', 'customer', 'order', 'coupon', 'review'];
        $currentStep = null;
        if ($migration->status === 'running') {
            foreach ($stepOrder as $step) {
                $stepCounts = $counts[$step] ?? [];
                $hasRunning = ($stepCounts['running'] ?? 0) > 0;
                $hasPending = ($stepCounts['pending'] ?? 0) > 0;
                if ($hasRunning || $hasPending) {
                    $currentStep = $step;
                    break;
                }
            }
        }

        // Last activity
        $lastLog = $migration->logs()
            ->orderByDesc('created_at')
            ->first(['message', 'entity_type', 'level', 'created_at']);

        return response()->json([
            'migration' => [
                'id' => $migration->id,
                'name' => $migration->name,
                'status' => $migration->status,
                'is_dry_run' => $migration->is_dry_run,
                'settings' => $migration->settings,
                'sync_mode' => $migration->sync_mode,
                'conflict_strategy' => $migration->conflict_strategy,
                'clean_woocommerce' => $migration->clean_woocommerce,
                'started_at' => $migration->started_at?->toIso8601String(),
                'finished_at' => $migration->finished_at?->toIso8601String(),
                'created_at' => $migration->created_at->toIso8601String(),
            ],
            'counts' => $counts,
            'summary' => [
                'total' => $totalAll,
                'success' => $totalSuccess,
                'failed' => $totalFailed,
                'pending' => $totalPending,
                'running' => $totalRunning,
                'skipped' => $totalSkipped,
            ],
            'timing' => [
                'elapsed_seconds' => $elapsedSeconds,
                'eta_seconds' => $etaSeconds,
            ],
            'current_step' => $currentStep,
            'last_activity' => $lastLog ? [
                'message' => $lastLog->message,
                'entity_type' => $lastLog->entity_type,
                'level' => $lastLog->level,
                'created_at' => $lastLog->created_at?->toIso8601String(),
            ] : null,
            'recent_errors' => $recentErrors,
            'recent_warnings' => $recentWarnings,
        ]);
    }

    public function show(MigrationRun $migration): Response
    {
        return Inertia::render('Migration/Show', [
            'migrationId' => $migration->id,
        ]);
    }

    public function logs(Request $request, MigrationRun $migration): JsonResponse
    {
        $validated = $request->validate([
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:500',
            'entity_type' => 'nullable|string',
            'level' => 'nullable|string|in:debug,info,warning,error',
        ]);

        $query = $migration->logs()->orderByDesc('created_at');

        if (! empty($validated['entity_type'])) {
            $query->where('entity_type', $validated['entity_type']);
        }

        if (! empty($validated['level'])) {
            $query->where('level', $validated['level']);
        }

        $logs = $query->paginate($validated['per_page'] ?? 100);

        return response()->json($logs);
    }

    public function showLogs(MigrationRun $migration): Response
    {
        return Inertia::render('Migration/Log', [
            'migrationId' => $migration->id,
        ]);
    }

    public function pause(MigrationRun $migration): JsonResponse
    {
        $migration->markPaused();

        return response()->json(['message' => 'Migration paused']);
    }

    public function resume(MigrationRun $migration): JsonResponse
    {
        $migration->update(['status' => 'running']);

        return response()->json(['message' => 'Migration resumed']);
    }

    public function cancel(MigrationRun $migration): JsonResponse
    {
        $migration->markFailed();
        app(\App\Services\CancellationService::class)->cancel($migration->id);

        return response()->json(['message' => 'Migration cancelled']);
    }

    public function pingShopware(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'db_host' => 'required|string',
            'db_port' => 'required|integer|min:1|max:65535',
            'db_database' => 'required|string',
            'db_username' => 'required|string',
            'db_password' => 'required|string',
        ]);

        try {
            $db = new ShopwareDB($validated);
            $connected = $db->ping();

            return response()->json(['connected' => $connected]);
        } catch (\Exception $e) {
            Log::warning('Shopware ping failed', ['error' => $e->getMessage()]);

            return response()->json(['connected' => false, 'error' => 'Connection failed']);
        }
    }

    public function pingWoocommerce(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'base_url' => 'required|string|url|starts_with:https://',
            'consumer_key' => 'required|string',
            'consumer_secret' => 'required|string',
        ]);

        try {
            $woo = new WooCommerceClient($validated);
            $connected = $woo->ping();

            return response()->json(['connected' => $connected]);
        } catch (\Exception $e) {
            Log::warning('WooCommerce ping failed', ['error' => $e->getMessage()]);

            return response()->json(['connected' => false, 'error' => 'Connection failed']);
        }
    }

    public function testConnections(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'shopware' => 'required|array',
            'shopware.db_host' => 'required|string',
            'shopware.db_port' => 'required|integer',
            'shopware.db_database' => 'required|string',
            'shopware.db_username' => 'required|string',
            'shopware.db_password' => 'required|string',
            'shopware.language_id' => 'nullable|string',
            'shopware.ssh' => 'nullable|array',
            'shopware.ssh.host' => 'required_with:shopware.ssh|string',
            'shopware.ssh.port' => 'nullable|integer',
            'shopware.ssh.username' => 'required_with:shopware.ssh|string',
            'shopware.ssh.password' => 'nullable|string',
            'shopware.ssh.key' => 'nullable|string',
            'woocommerce' => 'nullable|array',
            'woocommerce.base_url' => 'nullable|string',
            'woocommerce.consumer_key' => 'nullable|string',
            'woocommerce.consumer_secret' => 'nullable|string',
            'wordpress' => 'nullable|array',
            'wordpress.username' => 'nullable|string',
            'wordpress.app_password' => 'nullable|string',
            'wordpress.custom_headers' => 'nullable|array',
        ]);

        $results = [
            'shopware' => $this->testShopwareDetailed($validated['shopware']),
            'woocommerce' => null,
            'wordpress' => null,
        ];

        // Pass custom headers to both WooCommerce and WordPress clients
        $customHeaders = $validated['wordpress']['custom_headers'] ?? [];

        if (! empty($validated['woocommerce']['base_url'])) {
            $wooConfig = $validated['woocommerce'];
            if (! empty($customHeaders)) {
                $wooConfig['custom_headers'] = $customHeaders;
            }
            $results['woocommerce'] = $this->testWooCommerceDetailed($wooConfig);
        }

        if (! empty($validated['wordpress']['username'])) {
            $wpConfig = array_merge(
                $validated['wordpress'],
                ['base_url' => $validated['woocommerce']['base_url'] ?? '']
            );
            if (! empty($customHeaders)) {
                $wpConfig['custom_headers'] = $customHeaders;
            }
            $results['wordpress'] = $this->testWordPressDetailed($wpConfig);
        }

        $allPassed = $results['shopware']['success']
            && ($results['woocommerce']['success'] ?? true)
            && ($results['wordpress']['success'] ?? true);

        return response()->json([
            'success' => $allPassed,
            'results' => $results,
        ]);
    }

    public function listCmsPages(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'db_host' => 'required|string',
            'db_port' => 'required|integer',
            'db_database' => 'required|string',
            'db_username' => 'required|string',
            'db_password' => 'required|string',
            'language_id' => 'required|string',
            'live_version_id' => 'required|string',
            'ssh' => 'nullable|array',
        ]);

        try {
            $db = new ShopwareDB($validated);
            $reader = new CmsPageReader($db);
            $pages = $reader->fetchAll();

            return response()->json([
                'success' => true,
                'pages' => array_map(fn ($p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'type' => $p->type,
                    'locked' => (bool) $p->locked,
                ], $pages),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    protected function testShopwareDetailed(array $config): array
    {
        try {
            $db = new ShopwareDB($config);
            $result = $db->select('SELECT VERSION() as version, DATABASE() as db_name');

            $details = [
                'version' => $result[0]->version ?? 'Unknown',
                'database' => $result[0]->db_name ?? 'Unknown',
            ];

            // Check required tables
            $tables = ['product', 'category', 'customer', 'order'];
            $missingTables = [];
            foreach ($tables as $table) {
                $exists = $db->select("SHOW TABLES LIKE '{$table}'");
                if (empty($exists)) {
                    $missingTables[] = $table;
                }
            }

            if (! empty($missingTables)) {
                return [
                    'success' => false,
                    'error' => 'Missing tables: '.implode(', ', $missingTables),
                    'details' => $details,
                ];
            }

            // Count products
            $count = $db->select('SELECT COUNT(*) as count FROM product WHERE parent_id IS NULL');
            $details['product_count'] = $count[0]->count ?? 0;

            // Test language ID if provided
            if (! empty($config['language_id'])) {
                $lang = $db->select('SELECT LOWER(HEX(id)) as id, name FROM language WHERE id = UNHEX(?)', [$config['language_id']]);
                if (! empty($lang)) {
                    $details['language'] = $lang[0]->name;
                } else {
                    return [
                        'success' => false,
                        'error' => 'Invalid language ID',
                        'details' => $details,
                    ];
                }
            }

            return [
                'success' => true,
                'details' => $details,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    protected function testWooCommerceDetailed(array $config): array
    {
        try {
            $woo = new WooCommerceClient($config);

            // Try multiple methods to get WooCommerce version
            $version = 'Unknown';

            // Method 1: Try root endpoint
            try {
                $root = $woo->get('');
                $version = $root['store']['wc_version'] ?? $root['version'] ?? null;

                if (! $version && isset($root['routes'])) {
                    // Root endpoint exists but no version, try to extract from namespace
                    $version = $root['namespace'] ?? null;
                }
            } catch (\Exception $e) {
                Log::debug('WooCommerce root endpoint failed', ['error' => $e->getMessage()]);
            }

            // Method 2: Try system_status endpoint
            if ($version === 'Unknown' || ! $version) {
                try {
                    $systemStatus = $woo->get('system_status');
                    Log::debug('WooCommerce system_status response', ['data' => $systemStatus]);
                    $version = $systemStatus['environment']['version']
                        ?? $systemStatus['environment']['wc_version']
                        ?? $systemStatus['wc_version']
                        ?? null;
                } catch (\Exception $e) {
                    Log::debug('WooCommerce system_status failed', ['error' => $e->getMessage()]);
                }
            }

            // Method 3: Try data endpoint
            if ($version === 'Unknown' || ! $version) {
                try {
                    $data = $woo->get('data');
                    Log::debug('WooCommerce data response', ['data' => $data]);
                    if (is_array($data) && ! empty($data)) {
                        foreach ($data as $item) {
                            if (isset($item['slug']) && $item['slug'] === 'wc/v3') {
                                $version = $item['name'] ?? null;
                                break;
                            }
                        }
                    }
                } catch (\Exception $e) {
                    Log::debug('WooCommerce data endpoint failed', ['error' => $e->getMessage()]);
                }
            }

            // Method 4: Try getting a product to confirm API works (version may stay unknown)
            if ($version === 'Unknown' || ! $version) {
                try {
                    $products = $woo->get('products', ['per_page' => 1]);
                    if (! empty($products)) {
                        // API works but version unknown - that's ok
                        $version = $version ?: 'Unknown (API accessible)';
                    }
                } catch (\Exception $e) {
                    Log::debug('WooCommerce products test failed', ['error' => $e->getMessage()]);
                }
            }

            $version = $version ?: 'Unknown';

            $details = ['version' => $version];

            // Test critical endpoints
            $woo->get('products', ['per_page' => 1]);
            $woo->get('products/categories', ['per_page' => 1]);

            return [
                'success' => true,
                'details' => $details,
            ];
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $statusCode = $e->getResponse()->getStatusCode();
            $errorMessage = $e->getMessage();

            // Detect Cloudflare Access or Zero Trust issues
            if ($statusCode === 302 || $statusCode === 403) {
                return [
                    'success' => false,
                    'error' => "Access blocked ({$statusCode}) - Check Zero Trust/Cloudflare Access configuration. Custom headers may be required.",
                ];
            }

            if ($statusCode === 401) {
                return [
                    'success' => false,
                    'error' => 'Authentication failed (401) - check WooCommerce consumer key and secret',
                ];
            }

            return [
                'success' => false,
                'error' => "API error ({$statusCode}): {$errorMessage}",
            ];
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();

            // Check if error message contains redirect/access keywords
            if (stripos($errorMessage, 'cloudflare') !== false || stripos($errorMessage, 'access') !== false) {
                return [
                    'success' => false,
                    'error' => 'Blocked by Zero Trust/Cloudflare Access - configure custom headers with Service Token credentials',
                ];
            }

            return [
                'success' => false,
                'error' => 'Connection failed: '.$errorMessage,
            ];
        }
    }

    protected function testWordPressDetailed(array $config): array
    {
        try {
            $wpClientConfig = [
                'base_url' => $config['base_url'],
                'wp_username' => $config['username'],
                'wp_app_password' => $config['app_password'],
            ];

            // Add custom headers if provided
            if (! empty($config['custom_headers'])) {
                $wpClientConfig['custom_headers'] = $config['custom_headers'];
            }

            $wpMedia = new WordPressMediaClient($wpClientConfig);

            // First, test if API is accessible and authentication works
            $apiTest = $wpMedia->testApiAccess();
            if (! $apiTest['success']) {
                // Check if error indicates Cloudflare/Zero Trust blocking
                if (stripos($apiTest['error'], 'cloudflare') !== false || stripos($apiTest['error'], 'access') !== false) {
                    $apiTest['error'] .= ' - Configure custom headers with Cloudflare Service Token';
                }

                return $apiTest;
            }

            // API is accessible, now try test upload
            $testContent = 'Connection test from Shopware Migration Tool';
            $mediaId = $wpMedia->upload($testContent, 'migration-test-'.time().'.txt', 'text/plain');

            if ($mediaId) {
                return [
                    'success' => true,
                    'details' => [
                        'authenticated_as' => $apiTest['user'] ?? 'Unknown',
                        'test_upload_id' => $mediaId,
                    ],
                ];
            }

            return [
                'success' => false,
                'error' => 'Authentication OK but upload failed - check media upload permissions',
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}

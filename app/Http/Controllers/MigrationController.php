<?php

namespace App\Http\Controllers;

use App\Jobs\MigrateCategoriesJob;
use App\Jobs\MigrateCmsPagesJob;
use App\Jobs\MigrateCouponsJob;
use App\Jobs\MigrateCustomersJob;
use App\Jobs\MigrateManufacturersJob;
use App\Jobs\MigrateOrdersJob;
use App\Jobs\MigrateProductsJob;
use App\Jobs\MigrateReviewsJob;
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
        ]);

        $migration = MigrationRun::create([
            'name' => $validated['name'],
            'settings' => $validated['settings'],
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

        $jobs = array_merge($jobs, [
            new MigrateManufacturersJob($migration->id),
            new MigrateTaxesJob($migration->id),
            new MigrateCategoriesJob($migration->id),
            new MigrateProductsJob($migration->id),
            new MigrateCustomersJob($migration->id),
            new MigrateOrdersJob($migration->id),
            new MigrateCouponsJob($migration->id),
            new MigrateReviewsJob($migration->id),
        ]);

        // Add CMS pages migration if requested
        $cmsOptions = $validated['cms_options'] ?? [];
        if (! empty($cmsOptions['migrate_all'])) {
            $jobs[] = new MigrateCmsPagesJob($migration->id);
        } elseif (! empty($cmsOptions['selected_ids'])) {
            $jobs[] = new MigrateCmsPagesJob($migration->id, $cmsOptions['selected_ids']);
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

            if ($totalSuccess > 0 && $totalPending + $totalRunning > 0 && $elapsedSeconds > 0) {
                $rate = $totalSuccess / $elapsedSeconds;
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
        ]);

        $results = [
            'shopware' => $this->testShopwareDetailed($validated['shopware']),
            'woocommerce' => null,
            'wordpress' => null,
        ];

        if (! empty($validated['woocommerce']['base_url'])) {
            $results['woocommerce'] = $this->testWooCommerceDetailed($validated['woocommerce']);
        }

        if (! empty($validated['wordpress']['username'])) {
            $results['wordpress'] = $this->testWordPressDetailed(
                array_merge($validated['wordpress'], ['base_url' => $validated['woocommerce']['base_url'] ?? ''])
            );
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
            $systemStatus = $woo->get('system_status');

            $details = [
                'version' => $systemStatus['environment']['version'] ?? 'Unknown',
            ];

            // Test endpoints
            $woo->get('products', ['per_page' => 1]);
            $woo->get('products/categories', ['per_page' => 1]);

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

    protected function testWordPressDetailed(array $config): array
    {
        try {
            $wpMedia = new WordPressMediaClient([
                'base_url' => $config['base_url'],
                'wp_username' => $config['username'],
                'wp_app_password' => $config['app_password'],
            ]);

            // Try test upload
            $testContent = 'Connection test';
            $mediaId = $wpMedia->upload($testContent, 'test-'.time().'.txt', 'text/plain');

            if ($mediaId) {
                return [
                    'success' => true,
                    'details' => [
                        'test_upload_id' => $mediaId,
                    ],
                ];
            }

            return [
                'success' => false,
                'error' => 'Upload test failed',
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}

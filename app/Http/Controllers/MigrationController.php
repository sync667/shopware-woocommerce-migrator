<?php

namespace App\Http\Controllers;

use App\Jobs\MigrateCategoriesJob;
use App\Jobs\MigrateManufacturersJob;
use App\Jobs\MigrateProductAttributesJob;
use App\Jobs\MigrateProductsJob;
use App\Jobs\MigrateTaxesJob;
use App\Models\MigrationRun;
use App\Services\ShopwareDB;
use App\Services\WooCommerceClient;
use App\Services\WordPressMediaClient;
use App\Shopware\Readers\CmsPageReader;
use App\Shopware\Readers\ProductStreamReader;
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
            'stream_options' => 'nullable|array',
            'stream_options.migrate_streams' => 'nullable|boolean',
            'omnibus_options' => 'nullable|array',
            'omnibus_options.enabled' => 'nullable|boolean',
            'newsletter_options' => 'nullable|array',
            'newsletter_options.enabled' => 'nullable|boolean',
            'wishlist_options' => 'nullable|array',
            'wishlist_options.enabled' => 'nullable|boolean',
            'companion_options' => 'nullable|array',
            'companion_options.block_purchase_on_closeout' => 'nullable|boolean',
            'companion_options.delivery_tiers_enabled' => 'nullable|boolean',
            'cleanup_options' => 'nullable|array',
            'cleanup_options.delete_media' => 'nullable|boolean',
            'cleanup_options.media_mode' => 'nullable|string|in:migrated_only,all',
            'settings' => 'required|array',
            'settings.shopware' => 'required|array',
            'settings.shopware.db_host' => 'required|string',
            'settings.shopware.db_port' => 'required|integer|min:1|max:65535',
            'settings.shopware.db_database' => 'required|string',
            'settings.shopware.db_username' => 'required|string',
            'settings.shopware.db_password' => 'required|string',
            'settings.shopware.language_id' => 'required|string',
            'settings.shopware.live_version_id' => 'required|string',
            'settings.shopware.primary_sales_channel' => 'nullable|string',
            'settings.shopware.upsell_group_names' => 'nullable|array',
            'settings.shopware.upsell_group_names.*' => 'string',
            'settings.shopware.base_url' => 'required|string|url|starts_with:https://',
            'settings.shopware.ssh' => 'nullable|array',
            'settings.shopware.ssh.host' => 'required_with:settings.shopware.ssh|string',
            'settings.shopware.ssh.port' => 'nullable|integer',
            'settings.shopware.ssh.username' => 'required_with:settings.shopware.ssh|string',
            'settings.shopware.ssh.password' => 'nullable|string',
            'settings.shopware.ssh.key' => 'nullable|string',
            'settings.shopware.custom_headers' => 'nullable|array',
            'settings.shopware.custom_headers.*' => 'nullable|string',
            'settings.woocommerce' => 'required|array',
            'settings.woocommerce.base_url' => 'required|string|url|starts_with:https://',
            'settings.woocommerce.consumer_key' => 'required|string',
            'settings.woocommerce.consumer_secret' => 'required|string',
            'settings.woocommerce.db_host' => 'nullable|string',
            'settings.woocommerce.db_port' => 'nullable|integer|min:1|max:65535',
            'settings.woocommerce.db_database' => 'nullable|string',
            'settings.woocommerce.db_username' => 'nullable|string',
            'settings.woocommerce.db_password' => 'nullable|string',
            'settings.woocommerce.table_prefix' => 'nullable|string|regex:/^[A-Za-z0-9_]+$/',
            'settings.woocommerce.preserve_order_ids' => 'nullable|boolean',
            'settings.woocommerce.db_ssh' => 'nullable|array',
            'settings.woocommerce.db_ssh.host' => 'required_with:settings.woocommerce.db_ssh|string',
            'settings.woocommerce.db_ssh.port' => 'nullable|integer',
            'settings.woocommerce.db_ssh.username' => 'required_with:settings.woocommerce.db_ssh|string',
            'settings.woocommerce.db_ssh.password' => 'nullable|string',
            'settings.woocommerce.db_ssh.key' => 'nullable|string',
            'settings.wordpress' => 'required|array',
            'settings.wordpress.username' => 'required|string',
            'settings.wordpress.app_password' => 'required|string',
            'settings.wordpress.custom_headers' => 'nullable|array',
            'settings.wordpress.custom_headers.*' => 'nullable|string',
        ]);

        // Cleanup is irreversible — never silently combine it with a delta sync, which
        // would nuke everything and then only re-import the changed-since subset (i.e.
        // a near-empty re-population). Operator must explicitly switch to full sync.
        if (($validated['clean_woocommerce'] ?? false) && ($validated['sync_mode'] ?? 'full') === 'delta') {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'clean_woocommerce' => 'Cleanup cannot be combined with delta sync — switch sync_mode to "full" or uncheck "Clean WooCommerce Before Migration".',
            ]);
        }

        $migration = MigrationRun::create([
            'name' => $validated['name'],
            'settings' => array_merge($validated['settings'], [
                'cms_options' => $validated['cms_options'] ?? [],
                'stream_options' => $validated['stream_options'] ?? [],
                'omnibus_options' => $validated['omnibus_options'] ?? [],
                'newsletter_options' => $validated['newsletter_options'] ?? [],
                'wishlist_options' => $validated['wishlist_options'] ?? [],
                'companion_options' => $validated['companion_options'] ?? [],
                'cleanup_options' => $validated['cleanup_options'] ?? [],
            ]),
            'is_dry_run' => $validated['is_dry_run'] ?? false,
            'clean_woocommerce' => $validated['clean_woocommerce'] ?? false,
            'sync_mode' => $validated['sync_mode'] ?? 'full',
            'conflict_strategy' => $validated['conflict_strategy'] ?? 'shopware_wins',
            'status' => 'pending',
        ]);

        $migration->markRunning();

        $jobs = [];

        if (($validated['clean_woocommerce'] ?? false) && ! ($validated['is_dry_run'] ?? false)) {
            foreach (\App\Services\WooCommerceCleanup::entitiesFor($migration) as $entity) {
                $jobs[] = new \App\Jobs\CleanWooCommerceJob($migration->id, $entity);
            }
        }

        // Products batch dispatches customers batch via then(),
        // which in turn dispatches orders → coupons → reviews → [cms] → completion.
        $jobs = array_merge($jobs, [
            new MigrateManufacturersJob($migration->id),
            new MigrateTaxesJob($migration->id),
            new MigrateCategoriesJob($migration->id),
            new MigrateProductAttributesJob($migration->id),
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

        $lastLog = $migration->logs()
            ->orderByDesc('created_at')
            ->first(['message', 'entity_type', 'level', 'created_at']);

        return response()->json([
            'migration' => [
                'id' => $migration->id,
                'name' => $migration->name,
                'status' => $migration->status,
                'is_dry_run' => $migration->is_dry_run,
                'settings' => $this->redactedSettings($migration->settings),
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
            'artifacts' => $this->availableArtifacts($migration),
        ]);
    }

    /** @return array<string, array{label: string, url: string, size_bytes: int}> */
    protected function availableArtifacts(MigrationRun $migration): array
    {
        $files = [
            'redirects' => ['label' => 'SEO Redirects (CSV)', 'file' => 'redirects.csv'],
            'newsletter' => ['label' => 'Newsletter Recipients (CSV)', 'file' => 'newsletter_recipients.csv'],
            'wishlists' => ['label' => 'Customer Wishlists (CSV)', 'file' => 'wishlists.csv'],
        ];

        $out = [];
        foreach ($files as $key => $meta) {
            $path = storage_path('app/migrations/'.$migration->id.'/'.$meta['file']);
            if (! is_file($path)) {
                continue;
            }
            $out[$key] = [
                'label' => $meta['label'],
                'url' => route('migrations.download', ['migration' => $migration->id, 'artifact' => $key]),
                'size_bytes' => (int) filesize($path),
            ];
        }

        return $out;
    }

    /**
     * Returns a sanitized copy of migration settings safe for HTTP responses.
     *
     * The raw settings blob contains Shopware DB credentials, SSH credentials,
     * WooCommerce consumer secrets, and WordPress app passwords; exposing it
     * via /status would leak credentials to anyone with a valid session.
     *
     * @param  array<string, mixed>|null  $settings
     * @return array<string, mixed>
     */
    protected function redactedSettings(?array $settings): array
    {
        if (! is_array($settings)) {
            return [];
        }

        $redact = static function (mixed $value): mixed {
            return ($value === null || $value === '') ? null : '***';
        };

        return [
            'shopware' => [
                'db_host' => $settings['shopware']['db_host'] ?? null,
                'db_port' => $settings['shopware']['db_port'] ?? null,
                'db_database' => $settings['shopware']['db_database'] ?? null,
                'db_username' => $settings['shopware']['db_username'] ?? null,
                'db_password' => $redact($settings['shopware']['db_password'] ?? null),
                'language_id' => $settings['shopware']['language_id'] ?? null,
                'live_version_id' => $settings['shopware']['live_version_id'] ?? null,
                'primary_sales_channel' => $settings['shopware']['primary_sales_channel'] ?? null,
                'upsell_group_names' => $settings['shopware']['upsell_group_names'] ?? [],
                'base_url' => $settings['shopware']['base_url'] ?? null,
                'ssh' => isset($settings['shopware']['ssh']) ? [
                    'host' => $settings['shopware']['ssh']['host'] ?? null,
                    'port' => $settings['shopware']['ssh']['port'] ?? null,
                    'username' => $settings['shopware']['ssh']['username'] ?? null,
                    'password' => $redact($settings['shopware']['ssh']['password'] ?? null),
                    'key' => $redact($settings['shopware']['ssh']['key'] ?? null),
                ] : null,
                'custom_headers' => array_map($redact, $settings['shopware']['custom_headers'] ?? []),
            ],
            'woocommerce' => [
                'base_url' => $settings['woocommerce']['base_url'] ?? null,
                'consumer_key' => $redact($settings['woocommerce']['consumer_key'] ?? null),
                'consumer_secret' => $redact($settings['woocommerce']['consumer_secret'] ?? null),
                'db_host' => $settings['woocommerce']['db_host'] ?? null,
                'db_port' => $settings['woocommerce']['db_port'] ?? null,
                'db_database' => $settings['woocommerce']['db_database'] ?? null,
                'db_username' => $settings['woocommerce']['db_username'] ?? null,
                'db_password' => $redact($settings['woocommerce']['db_password'] ?? null),
                'table_prefix' => $settings['woocommerce']['table_prefix'] ?? null,
                'preserve_order_ids' => (bool) ($settings['woocommerce']['preserve_order_ids'] ?? false),
                'db_ssh' => isset($settings['woocommerce']['db_ssh']) ? [
                    'host' => $settings['woocommerce']['db_ssh']['host'] ?? null,
                    'port' => $settings['woocommerce']['db_ssh']['port'] ?? null,
                    'username' => $settings['woocommerce']['db_ssh']['username'] ?? null,
                    'password' => $redact($settings['woocommerce']['db_ssh']['password'] ?? null),
                    'key' => $redact($settings['woocommerce']['db_ssh']['key'] ?? null),
                ] : null,
            ],
            'wordpress' => [
                'username' => $settings['wordpress']['username'] ?? null,
                'app_password' => $redact($settings['wordpress']['app_password'] ?? null),
                'custom_headers' => array_map($redact, $settings['wordpress']['custom_headers'] ?? []),
            ],
            'cms_options' => $settings['cms_options'] ?? [],
            'stream_options' => $settings['stream_options'] ?? [],
            'omnibus_options' => $settings['omnibus_options'] ?? [],
            'newsletter_options' => $settings['newsletter_options'] ?? [],
            'wishlist_options' => $settings['wishlist_options'] ?? [],
            'companion_options' => $settings['companion_options'] ?? [],
            'cleanup_options' => $settings['cleanup_options'] ?? [],
        ];
    }

    public function show(MigrationRun $migration): Response
    {
        return Inertia::render('Migration/Show', [
            'migrationId' => $migration->id,
        ]);
    }

    public function downloadArtifact(MigrationRun $migration, string $artifact): \Symfony\Component\HttpFoundation\BinaryFileResponse|JsonResponse
    {
        $artifacts = [
            'redirects' => ['file' => 'redirects.csv', 'mime' => 'text/csv'],
            'newsletter' => ['file' => 'newsletter_recipients.csv', 'mime' => 'text/csv'],
            'wishlists' => ['file' => 'wishlists.csv', 'mime' => 'text/csv'],
        ];

        if (! isset($artifacts[$artifact])) {
            return response()->json(['error' => 'Unknown artifact'], 404);
        }

        $path = storage_path('app/migrations/'.$migration->id.'/'.$artifacts[$artifact]['file']);
        if (! is_file($path)) {
            return response()->json(['error' => 'File not generated for this migration'], 404);
        }

        return response()->download($path, "migration-{$migration->id}-{$artifacts[$artifact]['file']}", [
            'Content-Type' => $artifacts[$artifact]['mime'],
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
            'shopware.primary_sales_channel' => 'nullable|string',
            'shopware.ssh' => 'nullable|array',
            'shopware.ssh.host' => 'required_with:shopware.ssh|string',
            'shopware.ssh.port' => 'nullable|integer',
            'shopware.ssh.username' => 'required_with:shopware.ssh|string',
            'shopware.ssh.password' => 'nullable|string',
            'shopware.ssh.key' => 'nullable|string',
            'shopware.custom_headers' => 'nullable|array',
            'shopware.custom_headers.*' => 'nullable|string',
            'woocommerce' => 'nullable|array',
            'woocommerce.base_url' => 'nullable|string',
            'woocommerce.consumer_key' => 'nullable|string',
            'woocommerce.consumer_secret' => 'nullable|string',
            'woocommerce.db_host' => 'nullable|string',
            'woocommerce.db_port' => 'nullable|integer',
            'woocommerce.db_database' => 'nullable|string',
            'woocommerce.db_username' => 'nullable|string',
            'woocommerce.db_password' => 'nullable|string',
            'woocommerce.table_prefix' => 'nullable|string',
            'woocommerce.db_ssh' => 'nullable|array',
            'wordpress' => 'nullable|array',
            'wordpress.username' => 'nullable|string',
            'wordpress.app_password' => 'nullable|string',
            'wordpress.custom_headers' => 'nullable|array',
        ]);

        $results = [
            'shopware' => $this->testShopwareDetailed($validated['shopware']),
            'woocommerce' => null,
            'woocommerce_db' => null,
            'wordpress' => null,
        ];

        if (! empty($validated['woocommerce']['db_host'])) {
            $results['woocommerce_db'] = $this->testWooCommerceDB($validated['woocommerce']);
        }

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

    public function listProductStreams(Request $request): JsonResponse
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
            $reader = new ProductStreamReader($db);
            $streams = $reader->fetchAll();

            return response()->json([
                'success' => true,
                'streams' => array_map(fn ($s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                ], $streams),
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

            $count = $db->select('SELECT COUNT(*) as count FROM product WHERE parent_id IS NULL');
            $details['product_count'] = $count[0]->count ?? 0;

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

            // Probe content-type + status BEFORE assuming the API works. Otherwise a
            // Cloudflare Access HTML interstitial decodes to [] and silently looks
            // like a successful empty response across every probe endpoint below.
            $probe = $woo->testApiAccess();
            if (! $probe['success']) {
                if ($this->looksLikeCloudflareBlock($probe['error'])) {
                    $probe['error'] .= ' — Configure custom headers with a Cloudflare Service Token or add this server\'s IP to the CF Access bypass list.';
                }

                return $probe;
            }

            // Try multiple methods to get the version
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

            // Distinguish "version banner not exposed by any probe endpoint" from
            // "we never confirmed the API works" — the migration only needs the REST
            // API to be reachable, the version string is a cosmetic detail.
            if (! $version || $version === 'Unknown') {
                $version = 'REST v3 reachable (version banner not exposed)';
            }

            $details = ['version' => $version];

            $woo->get('products', ['per_page' => 1]);
            $woo->get('products/categories', ['per_page' => 1]);

            return [
                'success' => true,
                'details' => $details,
            ];
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $statusCode = $e->getResponse()->getStatusCode();
            $errorMessage = $e->getMessage();

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

            if ($this->looksLikeCloudflareBlock($errorMessage)) {
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

    /**
     * Pattern-match Cloudflare / Zero-Trust block messages with enough specificity
     * to not trip on the substring "access" embedded in unrelated text (e.g. "API
     * accessible but authentication failed"). The earlier loose `stripos(...,'access')`
     * heuristic generated misleading "configure Cloudflare" hints on plain WP
     * application-password failures.
     *
     * @internal
     */
    protected function looksLikeCloudflareBlock(string $message): bool
    {
        $needles = [
            'cloudflare',
            'cf-access',
            'cf access',
            'zero trust',
            'zero-trust',
            'access denied',
            'cf-ray',
        ];
        $haystack = strtolower($message);
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    protected function testWooCommerceDB(array $config): array
    {
        try {
            $db = new \App\Services\WooCommerceDB([
                'db_host' => $config['db_host'] ?? '',
                'db_port' => $config['db_port'] ?? 3306,
                'db_database' => $config['db_database'] ?? '',
                'db_username' => $config['db_username'] ?? '',
                'db_password' => $config['db_password'] ?? '',
                'table_prefix' => $config['table_prefix'] ?? 'wp_',
                'ssh' => $config['db_ssh'] ?? null,
            ]);

            $row = $db->select(
                'SELECT COUNT(*) AS c FROM '.$db->table('posts').' WHERE post_type = ?',
                ['shop_order']
            );
            $orders = (int) ($row[0]->c ?? 0);

            $db->disconnect();

            return [
                'success' => true,
                'details' => [
                    'database' => $config['db_database'],
                    'table_prefix' => $config['table_prefix'] ?? 'wp_',
                    'existing_orders' => $orders,
                ],
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
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

            if (! empty($config['custom_headers'])) {
                $wpClientConfig['custom_headers'] = $config['custom_headers'];
            }

            $wpMedia = new WordPressMediaClient($wpClientConfig);

            $apiTest = $wpMedia->testApiAccess();
            if (! $apiTest['success']) {
                if ($this->looksLikeCloudflareBlock($apiTest['error'])) {
                    $apiTest['error'] .= ' - Configure custom headers with Cloudflare Service Token';
                }

                return $apiTest;
            }

            $testContent = 'Connection test from Shopware Migration Tool';
            $mediaId = $wpMedia->upload($testContent, 'migration-test-'.time().'.txt', 'text/plain');

            if ($mediaId) {
                try {
                    $wpMedia->deleteMedia((int) $mediaId);
                } catch (\Throwable) {
                }

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

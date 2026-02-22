<?php

namespace App\Http\Controllers;

use App\Jobs\MigrateCategoriesJob;
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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Inertia\Inertia;
use Inertia\Response;

class MigrationController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'is_dry_run' => 'boolean',
            'settings' => 'required|array',
            'settings.shopware' => 'required|array',
            'settings.shopware.db_host' => 'required|string',
            'settings.shopware.db_port' => 'required|integer',
            'settings.shopware.db_database' => 'required|string',
            'settings.shopware.db_username' => 'required|string',
            'settings.shopware.db_password' => 'required|string',
            'settings.shopware.language_id' => 'required|string',
            'settings.shopware.live_version_id' => 'required|string',
            'settings.shopware.base_url' => 'required|string|url',
            'settings.woocommerce' => 'required|array',
            'settings.woocommerce.base_url' => 'required|string|url',
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
            'status' => 'pending',
        ]);

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

        return response()->json([
            'migration' => [
                'id' => $migration->id,
                'name' => $migration->name,
                'status' => $migration->status,
                'is_dry_run' => $migration->is_dry_run,
                'started_at' => $migration->started_at?->toIso8601String(),
                'finished_at' => $migration->finished_at?->toIso8601String(),
            ],
            'counts' => $counts,
            'recent_errors' => $recentErrors,
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
            'db_port' => 'required|integer',
            'db_database' => 'required|string',
            'db_username' => 'required|string',
            'db_password' => 'required|string',
        ]);

        try {
            $db = new ShopwareDB($validated);
            $connected = $db->ping();

            return response()->json(['connected' => $connected]);
        } catch (\Exception $e) {
            return response()->json(['connected' => false, 'error' => $e->getMessage()]);
        }
    }

    public function pingWoocommerce(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'base_url' => 'required|string|url',
            'consumer_key' => 'required|string',
            'consumer_secret' => 'required|string',
        ]);

        try {
            $woo = new WooCommerceClient($validated);
            $connected = $woo->ping();

            return response()->json(['connected' => $connected]);
        } catch (\Exception $e) {
            return response()->json(['connected' => false, 'error' => $e->getMessage()]);
        }
    }
}

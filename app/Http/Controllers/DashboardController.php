<?php

namespace App\Http\Controllers;

use App\Models\MigrationRun;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(): Response
    {
        $migrations = MigrationRun::orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn ($m) => [
                'id' => $m->id,
                'name' => $m->name,
                'status' => $m->status,
                'is_dry_run' => $m->is_dry_run,
                'sync_mode' => $m->sync_mode,
                'started_at' => $m->started_at?->toIso8601String(),
                'finished_at' => $m->finished_at?->toIso8601String(),
                'created_at' => $m->created_at->toIso8601String(),
                'clean_woocommerce' => (bool) $m->clean_woocommerce,
                'options' => [
                    'cms_pages' => ! empty($m->settings['cms_options']['migrate_all'])
                        || ! empty($m->settings['cms_options']['selected_ids']),
                    'product_streams' => ! empty($m->settings['stream_options']['migrate_streams']),
                    'omnibus' => ! empty($m->settings['omnibus_options']['enabled']),
                    'newsletter' => ! empty($m->settings['newsletter_options']['enabled']),
                    'wishlist' => ! empty($m->settings['wishlist_options']['enabled']),
                    'cleanup_media' => ! empty($m->settings['cleanup_options']['delete_media']),
                ],
            ]);

        return Inertia::render('Dashboard', [
            'migrations' => $migrations,
        ]);
    }

    public function settings(): Response
    {
        return Inertia::render('Settings');
    }
}

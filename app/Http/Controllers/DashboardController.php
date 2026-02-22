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
                'started_at' => $m->started_at?->toIso8601String(),
                'finished_at' => $m->finished_at?->toIso8601String(),
                'created_at' => $m->created_at->toIso8601String(),
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

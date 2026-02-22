<?php

namespace App\Http\Controllers;

use App\Models\MigrationRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LogController extends Controller
{
    public function index(MigrationRun $migration, Request $request): JsonResponse
    {
        $query = $migration->logs()->orderByDesc('created_at');

        if ($request->filled('entity_type')) {
            $query->where('entity_type', $request->input('entity_type'));
        }

        if ($request->filled('level')) {
            $query->where('level', $request->input('level'));
        }

        if ($request->filled('search')) {
            $query->where('message', 'like', '%'.$request->input('search').'%');
        }

        $logs = $query->paginate($request->input('per_page', 50));

        return response()->json($logs);
    }

    public function show(MigrationRun $migration): Response
    {
        return Inertia::render('Migration/Log', [
            'migrationId' => $migration->id,
        ]);
    }
}

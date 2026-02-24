<?php

namespace App\Http\Controllers;

use App\Services\DatabaseDumpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DumpUploadController extends Controller
{
    public function __construct(
        private DatabaseDumpService $dumpService
    ) {}

    /**
     * Upload and process a Shopware database dump file.
     */
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'dump_file' => 'required|file|max:2097152', // 2GB in KB
        ]);

        try {
            // Check Docker availability first
            if (! $this->dumpService->isDockerAvailable()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Docker is not available. Please install Docker to use the dump import feature.',
                ], 400);
            }

            $file = $request->file('dump_file');

            // Store the file
            $stored = $this->dumpService->store($file);

            // Extract SQL if compressed
            $sqlPath = $this->dumpService->extractSqlFile($stored['path']);

            // Validate the dump
            $validation = $this->dumpService->validateDump($sqlPath);

            if (! $validation['valid']) {
                return response()->json([
                    'success' => false,
                    'error' => 'Invalid Shopware database dump',
                    'validation' => $validation,
                ], 422);
            }

            // Spawn Docker container and import
            $connection = $this->dumpService->spawnAndImport($sqlPath, $stored['database_name']);

            Log::info('Database dump imported successfully', [
                'container' => $connection['container_name'],
                'port' => $connection['port'],
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Database dump imported successfully',
                'connection' => [
                    'db_host' => $connection['host'],
                    'db_port' => $connection['port'],
                    'db_database' => $connection['database'],
                    'db_username' => $connection['username'],
                    'db_password' => $connection['password'],
                ],
                'container_name' => $connection['container_name'],
                'validation' => $validation,
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Dump upload failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to process dump: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Check status of a spawned dump container.
     */
    public function status(Request $request): JsonResponse
    {
        $request->validate([
            'container_name' => 'required|string|regex:/^sw_dump_[a-zA-Z0-9]+$/',
        ]);

        $status = $this->dumpService->containerStatus($request->container_name);

        return response()->json([
            'success' => true,
            'status' => $status,
        ]);
    }

    /**
     * Clean up a spawned dump container.
     */
    public function cleanup(Request $request): JsonResponse
    {
        $request->validate([
            'container_name' => 'required|string|regex:/^sw_dump_[a-zA-Z0-9]+$/',
        ]);

        $removed = $this->dumpService->cleanup($request->container_name);

        return response()->json([
            'success' => $removed,
            'message' => $removed ? 'Container removed' : 'Failed to remove container',
        ]);
    }

    /**
     * Validate a dump file without importing it.
     */
    public function validateDump(Request $request): JsonResponse
    {
        $request->validate([
            'dump_file' => 'required|file|max:2097152',
        ]);

        try {
            $file = $request->file('dump_file');

            $stored = $this->dumpService->store($file);
            $sqlPath = $this->dumpService->extractSqlFile($stored['path']);
            $validation = $this->dumpService->validateDump($sqlPath);

            return response()->json([
                'success' => true,
                'validation' => $validation,
                'docker_available' => $this->dumpService->isDockerAvailable(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        }
    }
}

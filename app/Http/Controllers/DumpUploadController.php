<?php

namespace App\Http\Controllers;

use App\Services\DatabaseDumpService;
use App\Services\DumpChunkedUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DumpUploadController extends Controller
{
    public function __construct(
        private DatabaseDumpService $dumpService,
        private DumpChunkedUploadService $chunkedService,
    ) {}

    /**
     * Open a chunked upload session. The client posts the filename and total size,
     * we return a ULID upload_id + the chunk size to use. Frontend then POSTs each
     * chunk to /chunk and finally to /complete.
     */
    public function initChunked(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'filename' => 'required|string|max:255',
            'size' => 'required|integer|min:1',
            'mime' => 'nullable|string|max:127',
        ]);

        try {
            $session = $this->chunkedService->init(
                $validated['filename'],
                (int) $validated['size'],
                $validated['mime'] ?? null,
            );

            return response()->json([
                'success' => true,
                'upload_id' => $session['upload_id'],
                'chunk_size' => $session['chunk_size'],
                'max_total_size' => $session['max_total_size'],
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * Append a single chunk to an existing session. Chunks must be sent IN ORDER —
     * server verifies chunk_index matches the current write offset and rejects
     * mismatches so the client knows where to resume.
     */
    public function appendChunk(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'upload_id' => 'required|string',
            'chunk_index' => 'required|integer|min:0',
            'chunk' => 'required|file',
        ]);

        try {
            $progress = $this->chunkedService->appendChunk(
                $validated['upload_id'],
                (int) $validated['chunk_index'],
                $request->file('chunk'),
            );

            return response()->json([
                'success' => true,
                'received_bytes' => $progress['received_bytes'],
                'total_size' => $progress['total_size'],
                'complete' => $progress['complete'],
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * Finalize a chunked upload — verify the assembled file matches the declared
     * size, then run it through the same validate-extract-spawn flow as a one-shot
     * POST /upload would.
     */
    public function completeChunked(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'upload_id' => 'required|string',
        ]);

        $storedDirectory = null;

        try {
            if (! $this->dumpService->isDockerAvailable()) {
                $this->chunkedService->abort($validated['upload_id']);

                return response()->json([
                    'success' => false,
                    'error' => 'Docker is not available. Please install Docker to use the dump import feature.',
                ], 400);
            }

            $assembled = $this->chunkedService->complete($validated['upload_id']);
            $storedDirectory = $assembled['directory'];

            $sqlPath = $this->dumpService->extractSqlFile($assembled['path']);

            $validation = $this->dumpService->validateDump($sqlPath);

            if (! $validation['valid']) {
                $this->dumpService->cleanupFiles($storedDirectory);

                return response()->json([
                    'success' => false,
                    'error' => 'Invalid Shopware database dump',
                    'validation' => $validation,
                ], 422);
            }

            // Use the dump's basename (without extension) as the database name so
            // re-imports of the same dump end up in a predictable container DB name.
            $databaseName = pathinfo($assembled['filename'], PATHINFO_FILENAME) ?: 'shopware_dump';
            $connection = $this->dumpService->spawnAndImport($sqlPath, $databaseName);

            Log::info('Database dump imported via chunked upload', [
                'container' => $connection['container_name'],
                'port' => $connection['port'],
                'size' => $assembled['size'],
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
        } catch (\RuntimeException $e) {
            $this->chunkedService->abort($validated['upload_id']);
            if ($storedDirectory) {
                $this->dumpService->cleanupFiles($storedDirectory);
            }

            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            $this->chunkedService->abort($validated['upload_id']);
            if ($storedDirectory) {
                $this->dumpService->cleanupFiles($storedDirectory);
            }

            Log::error('Chunked dump complete failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to process dump: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Discard an in-progress chunked upload (operator cancelled, browser closed, etc).
     */
    public function abortChunked(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'upload_id' => 'required|string',
        ]);

        $this->chunkedService->abort($validated['upload_id']);

        return response()->json(['success' => true]);
    }

    /**
     * Upload and process a Shopware database dump file.
     */
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'dump_file' => 'required|file|max:2097152', // 2GB in KB
        ]);

        $storedDirectory = null;

        try {
            if (! $this->dumpService->isDockerAvailable()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Docker is not available. Please install Docker to use the dump import feature.',
                ], 400);
            }

            $file = $request->file('dump_file');

            $stored = $this->dumpService->store($file);
            $storedDirectory = $stored['directory'];

            $sqlPath = $this->dumpService->extractSqlFile($stored['path']);

            $validation = $this->dumpService->validateDump($sqlPath);

            if (! $validation['valid']) {
                $this->dumpService->cleanupFiles($storedDirectory);

                return response()->json([
                    'success' => false,
                    'error' => 'Invalid Shopware database dump',
                    'validation' => $validation,
                ], 422);
            }

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
            if ($storedDirectory) {
                $this->dumpService->cleanupFiles($storedDirectory);
            }

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            if ($storedDirectory) {
                $this->dumpService->cleanupFiles($storedDirectory);
            }

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
     * List every spawned dump MySQL container with full connection details so
     * the Settings page can recover the auto-populated password after a page
     * reload. Auth-gated via the same middleware as the rest of /api/dump/*.
     */
    public function listActive(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'docker_available' => $this->dumpService->isDockerAvailable(),
            'containers' => $this->dumpService->listActiveContainers(),
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

        $storedDirectory = null;

        try {
            $file = $request->file('dump_file');

            $stored = $this->dumpService->store($file);
            $storedDirectory = $stored['directory'];
            $sqlPath = $this->dumpService->extractSqlFile($stored['path']);
            $validation = $this->dumpService->validateDump($sqlPath);

            $this->dumpService->cleanupFiles($storedDirectory);

            return response()->json([
                'success' => true,
                'validation' => $validation,
                'docker_available' => $this->dumpService->isDockerAvailable(),
            ]);
        } catch (\Exception $e) {
            if ($storedDirectory) {
                $this->dumpService->cleanupFiles($storedDirectory);
            }

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        }
    }
}

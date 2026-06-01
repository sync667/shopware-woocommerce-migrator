<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Filesystem-backed chunked upload sessions for the Shopware DB dump endpoint.
 *
 * Why not memory / cache: a single 5 GB upload would chew the cache budget and
 * fragment redis. Each session lives in a directory under
 *   storage/app/dumps/.chunks/{upload_id}/
 *     data.part   — append-only assembling file
 *     meta.json   — original filename, expected size, mime, chunk_size, created_at
 *
 * Session ids are ULIDs so they sort by creation time, are URL-safe, and don't
 * collide across parallel uploads.
 */
class DumpChunkedUploadService
{
    /** Default chunk size advertised to the client. Smaller = more retry resilience, more requests. */
    public const DEFAULT_CHUNK_SIZE = 50 * 1024 * 1024; // 50 MB

    /** Hard upper bound on any single dump upload. Aligns with php.ini upload_max_filesize. */
    public const MAX_TOTAL_SIZE = 10 * 1024 * 1024 * 1024; // 10 GB

    /** Sessions older than this without a complete/abort call get pruned on the next init. */
    public const SESSION_TTL_SECONDS = 86400; // 24 h

    protected string $root;

    public function __construct(?string $root = null)
    {
        $this->root = $root ?? storage_path('app/dumps/.chunks');
    }

    /**
     * Start a new upload session. Validates the requested file shape, opportunistically
     * prunes stale sessions, and returns the id + chunk size the client should use.
     *
     * @return array{upload_id: string, chunk_size: int, max_total_size: int}
     */
    public function init(string $filename, int $totalSize, ?string $mime = null): array
    {
        if ($filename === '' || str_contains($filename, '/') || str_contains($filename, '\\')) {
            throw new RuntimeException('Invalid filename.');
        }
        if ($totalSize <= 0) {
            throw new RuntimeException('Total size must be greater than zero.');
        }
        if ($totalSize > self::MAX_TOTAL_SIZE) {
            $limitGb = self::MAX_TOTAL_SIZE / (1024 * 1024 * 1024);
            throw new RuntimeException("Dump exceeds the maximum allowed size of {$limitGb} GB.");
        }

        $this->pruneStaleSessions();

        $uploadId = (string) Str::ulid();
        $dir = $this->sessionDir($uploadId);

        if (! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new RuntimeException("Unable to create upload session directory at {$dir}.");
        }

        file_put_contents($dir.'/meta.json', json_encode([
            'filename' => $filename,
            'total_size' => $totalSize,
            'mime' => $mime,
            'chunk_size' => self::DEFAULT_CHUNK_SIZE,
            'created_at' => now()->toIso8601String(),
        ], JSON_PRETTY_PRINT));

        // Touch the data file so appendChunk can rely on it existing.
        touch($dir.'/data.part');

        return [
            'upload_id' => $uploadId,
            'chunk_size' => self::DEFAULT_CHUNK_SIZE,
            'max_total_size' => self::MAX_TOTAL_SIZE,
        ];
    }

    /**
     * Append a chunk to the session. The chunk_index is verified against the current
     * file size so out-of-order writes are rejected (clients must retry the failed
     * chunk before moving on). Returns the new received_bytes for progress display.
     *
     * @return array{received_bytes: int, total_size: int, complete: bool}
     */
    public function appendChunk(string $uploadId, int $chunkIndex, UploadedFile $chunk): array
    {
        $session = $this->openSession($uploadId);
        $dataPath = $session['dir'].'/data.part';
        $meta = $session['meta'];

        $currentSize = filesize($dataPath);
        $expectedIndex = intdiv($currentSize, $meta['chunk_size']);

        if ($chunkIndex !== $expectedIndex) {
            throw new RuntimeException(
                "Chunk index mismatch — server expected chunk #{$expectedIndex} (received {$currentSize}/{$meta['total_size']} bytes), got #{$chunkIndex}. Resend from chunk #{$expectedIndex}."
            );
        }

        $chunkBytes = $chunk->getSize();
        if ($currentSize + $chunkBytes > $meta['total_size']) {
            throw new RuntimeException(
                "Chunk would exceed declared total size ({$meta['total_size']} bytes)."
            );
        }

        $in = fopen($chunk->getPathname(), 'rb');
        if ($in === false) {
            throw new RuntimeException('Unable to open uploaded chunk for reading.');
        }
        $out = fopen($dataPath, 'ab');
        if ($out === false) {
            fclose($in);
            throw new RuntimeException('Unable to open session data file for writing.');
        }
        try {
            stream_copy_to_stream($in, $out);
        } finally {
            fclose($in);
            fclose($out);
        }

        $receivedBytes = filesize($dataPath);

        return [
            'received_bytes' => $receivedBytes,
            'total_size' => $meta['total_size'],
            'complete' => $receivedBytes >= $meta['total_size'],
        ];
    }

    /**
     * Finalize the upload: verifies the assembled size matches the declared total,
     * moves data.part into the dump storage area with the original filename, and
     * tears down the chunk session. Caller then runs the existing import flow on
     * the returned path.
     *
     * @return array{path: string, filename: string, size: int}
     */
    public function complete(string $uploadId): array
    {
        $session = $this->openSession($uploadId);
        $dataPath = $session['dir'].'/data.part';
        $meta = $session['meta'];

        $actualSize = filesize($dataPath);
        if ($actualSize !== $meta['total_size']) {
            throw new RuntimeException(
                "Size mismatch on complete: declared {$meta['total_size']} bytes, assembled {$actualSize}. Aborting."
            );
        }

        $targetDir = storage_path('app/dumps/'.Str::ulid());
        if (! mkdir($targetDir, 0755, true) && ! is_dir($targetDir)) {
            throw new RuntimeException("Unable to create dump target directory at {$targetDir}.");
        }

        $targetPath = $targetDir.'/'.basename($meta['filename']);
        if (! rename($dataPath, $targetPath)) {
            throw new RuntimeException('Failed to move assembled dump into storage.');
        }

        $this->deleteSession($uploadId);

        return [
            'path' => $targetPath,
            'directory' => $targetDir,
            'filename' => $meta['filename'],
            'size' => $actualSize,
        ];
    }

    public function abort(string $uploadId): void
    {
        $this->deleteSession($uploadId);
    }

    /**
     * @return array{dir: string, meta: array{filename: string, total_size: int, mime: ?string, chunk_size: int, created_at: string}}
     */
    protected function openSession(string $uploadId): array
    {
        if (! $this->isValidUploadId($uploadId)) {
            throw new RuntimeException('Invalid upload_id.');
        }

        $dir = $this->sessionDir($uploadId);
        if (! is_dir($dir)) {
            throw new RuntimeException('Upload session not found or already finalized.');
        }

        $metaPath = $dir.'/meta.json';
        if (! is_file($metaPath)) {
            throw new RuntimeException('Upload session is corrupt (missing meta).');
        }
        $meta = json_decode((string) file_get_contents($metaPath), true);
        if (! is_array($meta) || ! isset($meta['total_size'], $meta['filename'], $meta['chunk_size'])) {
            throw new RuntimeException('Upload session is corrupt (bad meta).');
        }

        return ['dir' => $dir, 'meta' => $meta];
    }

    protected function deleteSession(string $uploadId): void
    {
        if (! $this->isValidUploadId($uploadId)) {
            return;
        }
        $dir = $this->sessionDir($uploadId);
        if (! is_dir($dir)) {
            return;
        }
        foreach (glob($dir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }

    /**
     * Remove session directories older than SESSION_TTL_SECONDS. Called from init()
     * so we don't need a cron — every fresh upload sweeps stragglers.
     */
    protected function pruneStaleSessions(): void
    {
        if (! is_dir($this->root)) {
            return;
        }

        $cutoff = time() - self::SESSION_TTL_SECONDS;
        foreach (scandir($this->root) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $this->root.'/'.$entry;
            if (! is_dir($path)) {
                continue;
            }
            if (filemtime($path) < $cutoff) {
                foreach (glob($path.'/*') ?: [] as $file) {
                    @unlink($file);
                }
                @rmdir($path);
            }
        }
    }

    protected function sessionDir(string $uploadId): string
    {
        return $this->root.'/'.$uploadId;
    }

    protected function isValidUploadId(string $uploadId): bool
    {
        // ULIDs are 26 chars of Crockford base32. Defensive check before touching disk.
        return (bool) preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $uploadId);
    }
}

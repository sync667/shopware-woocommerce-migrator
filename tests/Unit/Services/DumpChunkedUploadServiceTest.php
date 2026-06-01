<?php

namespace Tests\Unit\Services;

use App\Services\DumpChunkedUploadService;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class DumpChunkedUploadServiceTest extends TestCase
{
    private string $tmpRoot;

    private DumpChunkedUploadService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir().'/dump-chunks-test-'.bin2hex(random_bytes(4));
        mkdir($this->tmpRoot, 0755, true);
        $this->service = new DumpChunkedUploadService($this->tmpRoot);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpRoot);
        parent::tearDown();
    }

    public function test_init_creates_session_with_metadata(): void
    {
        $session = $this->service->init('admin_sklep.sql', 1024 * 1024 * 100);

        $this->assertNotEmpty($session['upload_id']);
        $this->assertSame(DumpChunkedUploadService::DEFAULT_CHUNK_SIZE, $session['chunk_size']);
        $this->assertSame(DumpChunkedUploadService::MAX_TOTAL_SIZE, $session['max_total_size']);

        $dir = $this->tmpRoot.'/'.$session['upload_id'];
        $this->assertDirectoryExists($dir);
        $this->assertFileExists($dir.'/meta.json');
        $this->assertFileExists($dir.'/data.part');

        $meta = json_decode((string) file_get_contents($dir.'/meta.json'), true);
        $this->assertSame('admin_sklep.sql', $meta['filename']);
        $this->assertSame(1024 * 1024 * 100, $meta['total_size']);
    }

    public function test_init_rejects_path_traversal_in_filename(): void
    {
        $this->expectException(RuntimeException::class);
        $this->service->init('../../etc/passwd', 100);
    }

    public function test_init_rejects_oversized_total(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('exceeds the maximum allowed size');

        $this->service->init('huge.sql', DumpChunkedUploadService::MAX_TOTAL_SIZE + 1);
    }

    public function test_append_and_complete_roundtrip(): void
    {
        $payload = str_repeat('A', 300);
        $chunkSize = 100;

        // Use a tiny chunk size for the test by manually writing meta to keep this
        // unit test fast — but go through the public init() first so the directory
        // structure is real, then patch the meta in place to shrink chunk_size.
        $session = $this->service->init('mini.sql', strlen($payload));
        $dir = $this->tmpRoot.'/'.$session['upload_id'];
        $meta = json_decode((string) file_get_contents($dir.'/meta.json'), true);
        $meta['chunk_size'] = $chunkSize;
        file_put_contents($dir.'/meta.json', json_encode($meta));

        // Send 3 chunks of 100 bytes each
        for ($i = 0; $i < 3; $i++) {
            $tmp = tempnam(sys_get_temp_dir(), 'chunk');
            file_put_contents($tmp, substr($payload, $i * $chunkSize, $chunkSize));
            $upload = new UploadedFile($tmp, "chunk-$i", null, null, true);
            $progress = $this->service->appendChunk($session['upload_id'], $i, $upload);

            $this->assertSame(($i + 1) * $chunkSize, $progress['received_bytes']);
            $this->assertSame($i === 2, $progress['complete']);
        }

        $result = $this->service->complete($session['upload_id']);
        $this->assertSame(strlen($payload), $result['size']);
        $this->assertSame('mini.sql', $result['filename']);
        $this->assertFileExists($result['path']);
        $this->assertSame($payload, file_get_contents($result['path']));

        // Session directory must be cleaned up
        $this->assertDirectoryDoesNotExist($this->tmpRoot.'/'.$session['upload_id']);

        // Cleanup of the moved dump
        $this->rrmdir($result['directory']);
    }

    public function test_append_rejects_out_of_order_chunk(): void
    {
        $session = $this->service->init('mini.sql', 300);
        $dir = $this->tmpRoot.'/'.$session['upload_id'];
        $meta = json_decode((string) file_get_contents($dir.'/meta.json'), true);
        $meta['chunk_size'] = 100;
        file_put_contents($dir.'/meta.json', json_encode($meta));

        $tmp = tempnam(sys_get_temp_dir(), 'chunk');
        file_put_contents($tmp, str_repeat('B', 100));
        $upload = new UploadedFile($tmp, 'chunk-skip', null, null, true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Chunk index mismatch');
        $this->service->appendChunk($session['upload_id'], 5, $upload);
    }

    public function test_append_rejects_chunk_that_would_exceed_total(): void
    {
        $session = $this->service->init('mini.sql', 100);
        $dir = $this->tmpRoot.'/'.$session['upload_id'];
        $meta = json_decode((string) file_get_contents($dir.'/meta.json'), true);
        $meta['chunk_size'] = 200; // chunk larger than total declared
        file_put_contents($dir.'/meta.json', json_encode($meta));

        $tmp = tempnam(sys_get_temp_dir(), 'chunk');
        file_put_contents($tmp, str_repeat('C', 200));
        $upload = new UploadedFile($tmp, 'chunk-too-big', null, null, true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('exceed declared total size');
        $this->service->appendChunk($session['upload_id'], 0, $upload);
    }

    public function test_complete_fails_when_size_short(): void
    {
        $session = $this->service->init('mini.sql', 500);
        $dir = $this->tmpRoot.'/'.$session['upload_id'];
        $meta = json_decode((string) file_get_contents($dir.'/meta.json'), true);
        $meta['chunk_size'] = 100;
        file_put_contents($dir.'/meta.json', json_encode($meta));

        // Only send 100 of the declared 500 bytes
        $tmp = tempnam(sys_get_temp_dir(), 'chunk');
        file_put_contents($tmp, str_repeat('D', 100));
        $this->service->appendChunk($session['upload_id'], 0, new UploadedFile($tmp, 'short', null, null, true));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Size mismatch on complete');
        $this->service->complete($session['upload_id']);
    }

    public function test_abort_removes_session_directory(): void
    {
        $session = $this->service->init('mini.sql', 100);
        $dir = $this->tmpRoot.'/'.$session['upload_id'];
        $this->assertDirectoryExists($dir);

        $this->service->abort($session['upload_id']);
        $this->assertDirectoryDoesNotExist($dir);
    }

    public function test_abort_with_invalid_id_is_silently_noop(): void
    {
        $this->service->abort('not-a-ulid');
        $this->expectNotToPerformAssertions();
    }

    public function test_open_session_rejects_malformed_upload_id(): void
    {
        // appendChunk indirectly exercises openSession's validation
        $tmp = tempnam(sys_get_temp_dir(), 'chunk');
        file_put_contents($tmp, 'x');

        $this->expectException(RuntimeException::class);
        $this->service->appendChunk('not-a-valid-ulid', 0, new UploadedFile($tmp, 'x', null, null, true));
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}

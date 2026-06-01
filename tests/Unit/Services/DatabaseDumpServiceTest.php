<?php

namespace Tests\Unit\Services;

use App\Services\DatabaseDumpService;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class DatabaseDumpServiceTest extends TestCase
{
    private DatabaseDumpService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DatabaseDumpService;
    }

    public function test_validate_dump_detects_mysql_version(): void
    {
        $content = "-- MySQL dump 10.13  Distrib 8.0.32, for Linux (x86_64)\n"
            ."-- Server version\t8.0.32\n"
            ."CREATE TABLE `product` (id INT);\n"
            ."CREATE TABLE `category` (id INT);\n"
            ."CREATE TABLE `customer` (id INT);\n"
            ."CREATE TABLE `order` (id INT);\n"
            ."CREATE TABLE `language` (id INT);\n"
            ."CREATE TABLE `version` (id INT);\n";

        $tmpFile = tempnam(sys_get_temp_dir(), 'dump_');
        file_put_contents($tmpFile, $content);

        $result = $this->service->validateDump($tmpFile);

        $this->assertTrue($result['valid']);
        $this->assertEquals('8.0.32', $result['mysql_version']);
        $this->assertEmpty($result['tables_missing']);
        $this->assertContains('product', $result['tables_found']);
        $this->assertContains('category', $result['tables_found']);
        $this->assertContains('customer', $result['tables_found']);
        $this->assertContains('order', $result['tables_found']);
        $this->assertContains('language', $result['tables_found']);
        $this->assertContains('version', $result['tables_found']);

        unlink($tmpFile);
    }

    public function test_validate_dump_detects_missing_tables(): void
    {
        $content = "-- MySQL dump\n"
            ."-- Server version\t8.0.32\n"
            ."CREATE TABLE `product` (id INT);\n";

        $tmpFile = tempnam(sys_get_temp_dir(), 'dump_');
        file_put_contents($tmpFile, $content);

        $result = $this->service->validateDump($tmpFile);

        $this->assertFalse($result['valid']);
        $this->assertContains('category', $result['tables_missing']);
        $this->assertContains('customer', $result['tables_missing']);
        $this->assertContains('order', $result['tables_missing']);
        $this->assertContains('language', $result['tables_missing']);
        $this->assertContains('version', $result['tables_missing']);

        unlink($tmpFile);
    }

    public function test_validate_dump_detects_old_mysql_version(): void
    {
        $content = "-- MySQL dump\n"
            ."-- Server version\t5.5.60\n"
            ."CREATE TABLE `product` (id INT);\n"
            ."CREATE TABLE `category` (id INT);\n"
            ."CREATE TABLE `customer` (id INT);\n"
            ."CREATE TABLE `order` (id INT);\n"
            ."CREATE TABLE `language` (id INT);\n"
            ."CREATE TABLE `version` (id INT);\n";

        $tmpFile = tempnam(sys_get_temp_dir(), 'dump_');
        file_put_contents($tmpFile, $content);

        $result = $this->service->validateDump($tmpFile);

        $this->assertEquals('5.5.60', $result['mysql_version']);
        $this->assertNotEmpty($result['warnings']);
        $this->assertStringContainsString('below minimum', $result['warnings'][0]);

        unlink($tmpFile);
    }

    public function test_validate_dump_detects_mariadb(): void
    {
        $content = "-- MariaDB dump 10.19  Distrib 10.6.12-MariaDB\n"
            ."-- Server version\t10.6.12\n"
            ."CREATE TABLE `product` (id INT);\n"
            ."CREATE TABLE `category` (id INT);\n"
            ."CREATE TABLE `customer` (id INT);\n"
            ."CREATE TABLE `order` (id INT);\n"
            ."CREATE TABLE `language` (id INT);\n"
            ."CREATE TABLE `version` (id INT);\n";

        $tmpFile = tempnam(sys_get_temp_dir(), 'dump_');
        file_put_contents($tmpFile, $content);

        $result = $this->service->validateDump($tmpFile);

        $this->assertStringContainsString('MariaDB', $result['mysql_version']);

        unlink($tmpFile);
    }

    public function test_extract_sql_file_returns_path_for_sql(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'dump_').'.sql';
        file_put_contents($tmpFile, 'SELECT 1');

        $result = $this->service->extractSqlFile($tmpFile);

        $this->assertEquals($tmpFile, $result);

        unlink($tmpFile);
    }

    public function test_extract_sql_file_throws_for_unsupported_format(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unsupported file format');

        $tmpFile = tempnam(sys_get_temp_dir(), 'dump_').'.txt';
        file_put_contents($tmpFile, 'test');

        try {
            $this->service->extractSqlFile($tmpFile);
        } finally {
            unlink($tmpFile);
        }
    }

    public function test_store_rejects_invalid_extension(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid file type');

        $file = UploadedFile::fake()->create('dump.txt', 100);
        $this->service->store($file);
    }

    public function test_extract_zip_file(): void
    {
        $tmpDir = sys_get_temp_dir().'/dump_test_'.uniqid();
        mkdir($tmpDir, 0755, true);

        $sqlContent = "CREATE TABLE test (id INT);\n";
        $sqlFile = $tmpDir.'/dump.sql';
        file_put_contents($sqlFile, $sqlContent);

        $zipPath = $tmpDir.'/dump.zip';
        $zip = new \ZipArchive;
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFile($sqlFile, 'dump.sql');
        $zip->close();

        $result = $this->service->extractSqlFile($zipPath);

        $this->assertStringEndsWith('.sql', $result);
        $this->assertFileExists($result);

        $this->cleanupDirectory($tmpDir);
    }

    public function test_extract_zip_rejects_path_traversal(): void
    {
        $tmpDir = sys_get_temp_dir().'/dump_test_'.uniqid();
        mkdir($tmpDir, 0755, true);

        $zipPath = $tmpDir.'/malicious.zip';
        $zip = new \ZipArchive;
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('../../evil.sql', 'DROP TABLE users;');
        $zip->close();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Zip entry attempts to escape extraction directory');

        try {
            $this->service->extractSqlFile($zipPath);
        } finally {
            $this->cleanupDirectory($tmpDir);
        }
    }

    public function test_cleanup_files_removes_directory(): void
    {
        $tmpDir = sys_get_temp_dir().'/dump_test_'.uniqid();
        mkdir($tmpDir.'/subdir', 0755, true);
        file_put_contents($tmpDir.'/test.sql', 'test');
        file_put_contents($tmpDir.'/subdir/nested.sql', 'test');

        $this->service->cleanupFiles($tmpDir);

        $this->assertDirectoryDoesNotExist($tmpDir);
    }

    public function test_cleanup_files_handles_nonexistent_directory(): void
    {
        $this->expectNotToPerformAssertions();

        // Should not throw
        $this->service->cleanupFiles('/nonexistent/path/'.uniqid());
    }

    /**
     * Build a Closure-style Process::fake matcher. Pattern-keyed fakes only match
     * when the command is invoked as a string — our service uses argv arrays for
     * shell-safety, so we dispatch in PHP.
     */
    private function fakeDocker(array $handlers): \Closure
    {
        return function ($process) use ($handlers) {
            $cmd = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;
            foreach ($handlers as $prefix => $result) {
                if (str_starts_with($cmd, $prefix)) {
                    return $result;
                }
            }

            return \Illuminate\Support\Facades\Process::result(output: '', exitCode: 1);
        };
    }

    public function test_list_active_containers_returns_running_dump_containers(): void
    {
        $inspectJson = json_encode([[
            'State' => ['Status' => 'running'],
            'Created' => '2026-06-01T13:00:00Z',
            'Config' => ['Env' => [
                'MYSQL_ROOT_PASSWORD=secret123',
                'MYSQL_DATABASE=shopware',
                'PATH=/usr/local/sbin:/usr/local/bin',
            ]],
            'NetworkSettings' => ['Ports' => [
                '3306/tcp' => [['HostIp' => '0.0.0.0', 'HostPort' => '33060']],
            ]],
        ]]);

        \Illuminate\Support\Facades\Process::fake($this->fakeDocker([
            'docker info' => \Illuminate\Support\Facades\Process::result(output: 'ok'),
            'docker ps' => \Illuminate\Support\Facades\Process::result(output: "sw_dump_test123\n"),
            'docker inspect' => \Illuminate\Support\Facades\Process::result(output: $inspectJson),
        ]));

        $containers = $this->service->listActiveContainers();

        $this->assertCount(1, $containers);
        $this->assertSame('sw_dump_test123', $containers[0]['container_name']);
        $this->assertSame(33060, $containers[0]['port']);
        $this->assertSame('shopware', $containers[0]['database']);
        $this->assertSame('root', $containers[0]['username']);
        $this->assertSame('secret123', $containers[0]['password']);
        $this->assertSame('running', $containers[0]['status']);
    }

    public function test_list_active_containers_returns_empty_when_none_running(): void
    {
        \Illuminate\Support\Facades\Process::fake($this->fakeDocker([
            'docker info' => \Illuminate\Support\Facades\Process::result(output: 'ok'),
            'docker ps' => \Illuminate\Support\Facades\Process::result(output: ''),
        ]));

        $this->assertSame([], $this->service->listActiveContainers());
    }

    public function test_list_active_containers_sorts_newest_first(): void
    {
        $older = json_encode([[
            'State' => ['Status' => 'running'],
            'Created' => '2026-06-01T09:00:00Z',
            'Config' => ['Env' => ['MYSQL_ROOT_PASSWORD=a', 'MYSQL_DATABASE=shopware']],
            'NetworkSettings' => ['Ports' => ['3306/tcp' => [['HostPort' => '33060']]]],
        ]]);
        $newer = json_encode([[
            'State' => ['Status' => 'running'],
            'Created' => '2026-06-01T15:00:00Z',
            'Config' => ['Env' => ['MYSQL_ROOT_PASSWORD=b', 'MYSQL_DATABASE=shopware']],
            'NetworkSettings' => ['Ports' => ['3306/tcp' => [['HostPort' => '33061']]]],
        ]]);

        \Illuminate\Support\Facades\Process::fake($this->fakeDocker([
            'docker info' => \Illuminate\Support\Facades\Process::result(output: 'ok'),
            'docker ps' => \Illuminate\Support\Facades\Process::result(output: "sw_dump_old\nsw_dump_new\n"),
            'docker inspect sw_dump_old' => \Illuminate\Support\Facades\Process::result(output: $older),
            'docker inspect sw_dump_new' => \Illuminate\Support\Facades\Process::result(output: $newer),
        ]));

        $containers = $this->service->listActiveContainers();

        $this->assertCount(2, $containers);
        $this->assertSame('sw_dump_new', $containers[0]['container_name']);
        $this->assertSame('sw_dump_old', $containers[1]['container_name']);
    }

    public function test_list_active_containers_skips_containers_without_password(): void
    {
        $bad = json_encode([[
            'State' => ['Status' => 'running'],
            'Created' => '2026-06-01T13:00:00Z',
            'Config' => ['Env' => ['MYSQL_DATABASE=shopware']],
            'NetworkSettings' => ['Ports' => ['3306/tcp' => [['HostPort' => '33060']]]],
        ]]);

        \Illuminate\Support\Facades\Process::fake($this->fakeDocker([
            'docker info' => \Illuminate\Support\Facades\Process::result(output: 'ok'),
            'docker ps' => \Illuminate\Support\Facades\Process::result(output: "sw_dump_corrupt\n"),
            'docker inspect' => \Illuminate\Support\Facades\Process::result(output: $bad),
        ]));

        $this->assertSame([], $this->service->listActiveContainers());
    }

    public function test_list_active_containers_returns_empty_when_docker_unavailable(): void
    {
        \Illuminate\Support\Facades\Process::fake($this->fakeDocker([
            'docker info' => \Illuminate\Support\Facades\Process::result(output: '', exitCode: 1),
        ]));

        $this->assertSame([], $this->service->listActiveContainers());
    }

    private function cleanupDirectory(string $dir): void
    {
        $items = glob($dir.'/{,.}[!.,!..]*', GLOB_BRACE);
        foreach ($items as $item) {
            is_dir($item) ? $this->cleanupDirectory($item) : unlink($item);
        }
        rmdir($dir);
    }
}

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

        // Cleanup
        array_map('unlink', glob($tmpDir.'/*'));
        rmdir($tmpDir);
    }
}

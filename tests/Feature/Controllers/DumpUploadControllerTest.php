<?php

namespace Tests\Feature\Controllers;

use App\Models\User;
use App\Services\DatabaseDumpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class DumpUploadControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function authenticatedRequest(): static
    {
        return $this->withSession(['authenticated' => true, 'authenticated_at' => now()->toISOString()]);
    }

    public function test_upload_requires_authentication(): void
    {
        $response = $this->postJson('/api/dump/upload');

        $response->assertStatus(401);
    }

    public function test_upload_validates_file_is_required(): void
    {
        $response = $this->authenticatedRequest()
            ->postJson('/api/dump/upload', []);

        $response->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    public function test_upload_succeeds_with_valid_dump(): void
    {
        Storage::fake('local');

        $sqlContent = "-- MySQL dump\n-- Server version  8.0.32\n"
            ."CREATE TABLE `product` (id INT);\n"
            ."CREATE TABLE `category` (id INT);\n"
            ."CREATE TABLE `customer` (id INT);\n"
            ."CREATE TABLE `order` (id INT);\n"
            ."CREATE TABLE `language` (id INT);\n"
            ."CREATE TABLE `version` (id INT);\n";

        $file = UploadedFile::fake()->createWithContent('dump.sql', $sqlContent);

        $mockService = Mockery::mock(DatabaseDumpService::class);
        $mockService->shouldReceive('isDockerAvailable')->once()->andReturn(true);
        $mockService->shouldReceive('store')->once()->andReturn([
            'path' => '/tmp/test/dump.sql',
            'database_name' => 'shopware_dump_test',
        ]);
        $mockService->shouldReceive('extractSqlFile')->once()->andReturn('/tmp/test/dump.sql');
        $mockService->shouldReceive('validateDump')->once()->andReturn([
            'valid' => true,
            'mysql_version' => '8.0.32',
            'tables_found' => ['product', 'category', 'customer', 'order', 'language', 'version'],
            'tables_missing' => [],
            'warnings' => [],
        ]);
        $mockService->shouldReceive('spawnAndImport')->once()->andReturn([
            'host' => '127.0.0.1',
            'port' => 33060,
            'database' => 'shopware',
            'username' => 'root',
            'password' => 'testpass',
            'container_name' => 'sw_dump_test123',
        ]);

        $this->app->instance(DatabaseDumpService::class, $mockService);

        $response = $this->authenticatedRequest()
            ->postJson('/api/dump/upload', [
                'dump_file' => $file,
            ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Database dump imported successfully',
                'container_name' => 'sw_dump_test123',
            ])
            ->assertJsonStructure([
                'success',
                'message',
                'connection' => ['db_host', 'db_port', 'db_database', 'db_username', 'db_password'],
                'container_name',
                'validation',
            ]);
    }

    public function test_upload_fails_when_docker_unavailable(): void
    {
        $file = UploadedFile::fake()->createWithContent('dump.sql', '-- test');

        $mockService = Mockery::mock(DatabaseDumpService::class);
        $mockService->shouldReceive('isDockerAvailable')->once()->andReturn(false);

        $this->app->instance(DatabaseDumpService::class, $mockService);

        $response = $this->authenticatedRequest()
            ->postJson('/api/dump/upload', [
                'dump_file' => $file,
            ]);

        $response->assertStatus(400)
            ->assertJson([
                'success' => false,
                'error' => 'Docker is not available. Please install Docker to use the dump import feature.',
            ]);
    }

    public function test_upload_fails_with_invalid_dump(): void
    {
        $file = UploadedFile::fake()->createWithContent('dump.sql', '-- empty dump');

        $mockService = Mockery::mock(DatabaseDumpService::class);
        $mockService->shouldReceive('isDockerAvailable')->once()->andReturn(true);
        $mockService->shouldReceive('store')->once()->andReturn([
            'path' => '/tmp/test/dump.sql',
            'database_name' => 'shopware_dump_test',
        ]);
        $mockService->shouldReceive('extractSqlFile')->once()->andReturn('/tmp/test/dump.sql');
        $mockService->shouldReceive('validateDump')->once()->andReturn([
            'valid' => false,
            'mysql_version' => null,
            'tables_found' => [],
            'tables_missing' => ['product', 'category', 'customer', 'order', 'language', 'version'],
            'warnings' => ['Missing required Shopware tables'],
        ]);

        $this->app->instance(DatabaseDumpService::class, $mockService);

        $response = $this->authenticatedRequest()
            ->postJson('/api/dump/upload', [
                'dump_file' => $file,
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error' => 'Invalid Shopware database dump',
            ]);
    }

    public function test_status_requires_authentication(): void
    {
        $response = $this->postJson('/api/dump/status');

        $response->assertStatus(401);
    }

    public function test_status_validates_container_name(): void
    {
        $response = $this->authenticatedRequest()
            ->postJson('/api/dump/status', []);

        $response->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    public function test_status_rejects_invalid_container_name(): void
    {
        $response = $this->authenticatedRequest()
            ->postJson('/api/dump/status', [
                'container_name' => 'invalid; rm -rf /',
            ]);

        $response->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    public function test_status_returns_container_status(): void
    {
        $mockService = Mockery::mock(DatabaseDumpService::class);
        $mockService->shouldReceive('containerStatus')
            ->with('sw_dump_abc12345')
            ->once()
            ->andReturn(['running' => true, 'status' => 'running']);

        $this->app->instance(DatabaseDumpService::class, $mockService);

        $response = $this->authenticatedRequest()
            ->postJson('/api/dump/status', [
                'container_name' => 'sw_dump_abc12345',
            ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'status' => ['running' => true, 'status' => 'running'],
            ]);
    }

    public function test_cleanup_requires_authentication(): void
    {
        $response = $this->postJson('/api/dump/cleanup');

        $response->assertStatus(401);
    }

    public function test_cleanup_validates_container_name(): void
    {
        $response = $this->authenticatedRequest()
            ->postJson('/api/dump/cleanup', []);

        $response->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    public function test_cleanup_removes_container(): void
    {
        $mockService = Mockery::mock(DatabaseDumpService::class);
        $mockService->shouldReceive('cleanup')
            ->with('sw_dump_abc12345')
            ->once()
            ->andReturn(true);

        $this->app->instance(DatabaseDumpService::class, $mockService);

        $response = $this->authenticatedRequest()
            ->postJson('/api/dump/cleanup', [
                'container_name' => 'sw_dump_abc12345',
            ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Container removed',
            ]);
    }

    public function test_validate_endpoint_works(): void
    {
        $file = UploadedFile::fake()->createWithContent('dump.sql', '-- test dump');

        $mockService = Mockery::mock(DatabaseDumpService::class);
        $mockService->shouldReceive('store')->once()->andReturn([
            'path' => '/tmp/test/dump.sql',
            'database_name' => 'shopware_dump_test',
        ]);
        $mockService->shouldReceive('extractSqlFile')->once()->andReturn('/tmp/test/dump.sql');
        $mockService->shouldReceive('validateDump')->once()->andReturn([
            'valid' => true,
            'mysql_version' => '8.0.32',
            'tables_found' => ['product', 'category', 'customer', 'order', 'language', 'version'],
            'tables_missing' => [],
            'warnings' => [],
        ]);
        $mockService->shouldReceive('isDockerAvailable')->once()->andReturn(true);

        $this->app->instance(DatabaseDumpService::class, $mockService);

        $response = $this->authenticatedRequest()
            ->postJson('/api/dump/validate', [
                'dump_file' => $file,
            ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'docker_available' => true,
                'validation' => [
                    'valid' => true,
                    'mysql_version' => '8.0.32',
                ],
            ]);
    }
}

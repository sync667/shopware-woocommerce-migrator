<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

class DatabaseDumpService
{
    /**
     * Minimum MySQL version supported by Shopware 6
     */
    private const MIN_MYSQL_VERSION = '5.7';

    /**
     * Required Shopware tables to validate the dump
     */
    private const REQUIRED_TABLES = ['product', 'category', 'customer', 'order', 'language', 'version'];

    /**
     * Allowed file extensions
     */
    private const ALLOWED_EXTENSIONS = ['sql', 'gz', 'zip', 'tar.gz', 'tgz'];

    /**
     * Maximum file size (2GB)
     */
    private const MAX_FILE_SIZE = 2147483648;

    /**
     * Store and process the uploaded dump file.
     *
     * @return array{path: string, database_name: string}
     */
    public function store(UploadedFile $file): array
    {
        $this->validateFile($file);

        $databaseName = 'shopware_dump_'.Str::random(8);
        $directory = 'dumps/'.$databaseName;

        $file->storeAs($directory, $file->getClientOriginalName(), 'local');

        $storedPath = storage_path('app/'.$directory.'/'.$file->getClientOriginalName());

        return [
            'path' => $storedPath,
            'database_name' => $databaseName,
        ];
    }

    /**
     * Extract the SQL file from compressed archives.
     *
     * @return string Path to the extracted SQL file
     */
    public function extractSqlFile(string $filePath): string
    {
        $extension = $this->getFullExtension($filePath);

        if ($extension === 'sql') {
            return $filePath;
        }

        $extractDir = dirname($filePath);

        if ($extension === 'gz' || $extension === 'tar.gz' || $extension === 'tgz') {
            return $this->extractGz($filePath, $extractDir);
        }

        if ($extension === 'zip') {
            return $this->extractZip($filePath, $extractDir);
        }

        throw new \RuntimeException("Unsupported file format: {$extension}");
    }

    /**
     * Validate the SQL dump content for Shopware compatibility.
     *
     * @return array{valid: bool, mysql_version: string|null, tables_found: array, tables_missing: array, warnings: array}
     */
    public function validateDump(string $sqlPath): array
    {
        $warnings = [];
        $mysqlVersion = null;
        $tablesFound = [];

        // Read the first portion of the file to check headers and structure
        $handle = fopen($sqlPath, 'r');
        if (! $handle) {
            throw new \RuntimeException('Cannot read SQL file');
        }

        $headerContent = '';
        $lineCount = 0;
        $maxHeaderLines = 100;

        while (($line = fgets($handle)) !== false && $lineCount < $maxHeaderLines) {
            $headerContent .= $line;
            $lineCount++;
        }

        // Check MySQL version from dump header
        if (preg_match('/Server version\s+(\d+\.\d+(?:\.\d+)?)/', $headerContent, $matches)) {
            $mysqlVersion = $matches[1];

            if (version_compare($mysqlVersion, self::MIN_MYSQL_VERSION, '<')) {
                $warnings[] = "MySQL version {$mysqlVersion} is below minimum supported version ".self::MIN_MYSQL_VERSION;
            }
        }

        // Also check for MariaDB
        if (preg_match('/MariaDB/i', $headerContent)) {
            if (preg_match('/MariaDB.*?(\d+\.\d+(?:\.\d+)?)/', $headerContent, $matches)) {
                $mysqlVersion = 'MariaDB '.$matches[1];
            }
        }

        // Scan the entire file for CREATE TABLE statements
        rewind($handle);
        $fullContent = '';
        $chunkSize = 8192;

        while (! feof($handle)) {
            $fullContent .= fread($handle, $chunkSize);

            // Check for required tables in accumulated content
            foreach (self::REQUIRED_TABLES as $table) {
                if (! in_array($table, $tablesFound)) {
                    if (preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?'.$table.'`?\s/i', $fullContent)) {
                        $tablesFound[] = $table;
                    }
                }
            }

            // Keep memory usage reasonable by trimming processed content
            if (strlen($fullContent) > 65536) {
                $fullContent = substr($fullContent, -32768);
            }
        }

        fclose($handle);

        $tablesMissing = array_values(array_diff(self::REQUIRED_TABLES, $tablesFound));

        if (! empty($tablesMissing)) {
            $warnings[] = 'Missing required Shopware tables: '.implode(', ', $tablesMissing);
        }

        return [
            'valid' => empty($tablesMissing),
            'mysql_version' => $mysqlVersion,
            'tables_found' => $tablesFound,
            'tables_missing' => $tablesMissing,
            'warnings' => $warnings,
        ];
    }

    /**
     * Check if Docker is available on the system.
     */
    public function isDockerAvailable(): bool
    {
        $result = Process::timeout(10)->run('docker info');

        return $result->successful();
    }

    /**
     * Spawn a Docker MySQL container and import the dump.
     *
     * @return array{host: string, port: int, database: string, username: string, password: string, container_name: string}
     */
    public function spawnAndImport(string $sqlPath, string $databaseName): array
    {
        if (! $this->isDockerAvailable()) {
            throw new \RuntimeException('Docker is not available. Please install Docker to use the dump import feature.');
        }

        $containerName = 'sw_dump_'.Str::random(8);
        $port = $this->findAvailablePort();
        $password = Str::random(16);
        $dbName = 'shopware';

        // Spawn MySQL container
        $result = Process::timeout(30)->run([
            'docker', 'run', '-d',
            '--name', $containerName,
            '-e', 'MYSQL_ROOT_PASSWORD='.$password,
            '-e', 'MYSQL_DATABASE='.$dbName,
            '-p', $port.':3306',
            'mysql:8.0',
            '--default-authentication-plugin=mysql_native_password',
            '--innodb-flush-log-at-trx-commit=0',
        ]);

        if (! $result->successful()) {
            throw new \RuntimeException('Failed to start MySQL container: '.$result->errorOutput());
        }

        // Wait for MySQL to be ready
        $this->waitForMysql($containerName, $password);

        // Import the dump
        $this->importDump($containerName, $sqlPath, $password, $dbName);

        // Determine host - if running inside Docker, use host.docker.internal or gateway
        $host = $this->determineHost();

        return [
            'host' => $host,
            'port' => $port,
            'database' => $dbName,
            'username' => 'root',
            'password' => $password,
            'container_name' => $containerName,
        ];
    }

    /**
     * Clean up a spawned Docker container.
     */
    public function cleanup(string $containerName): bool
    {
        $result = Process::timeout(30)->run(['docker', 'rm', '-f', $containerName]);

        return $result->successful();
    }

    /**
     * Get status of a spawned container.
     *
     * @return array{running: bool, status: string}
     */
    public function containerStatus(string $containerName): array
    {
        $result = Process::timeout(10)->run([
            'docker', 'inspect', '--format={{.State.Status}}', $containerName,
        ]);

        if (! $result->successful()) {
            return ['running' => false, 'status' => 'not_found'];
        }

        $status = trim($result->output());

        return [
            'running' => $status === 'running',
            'status' => $status,
        ];
    }

    /**
     * Validate the uploaded file.
     */
    private function validateFile(UploadedFile $file): void
    {
        $extension = $this->getFullExtension($file->getClientOriginalName());

        if (! in_array($extension, self::ALLOWED_EXTENSIONS)) {
            throw new \InvalidArgumentException(
                'Invalid file type. Allowed: '.implode(', ', self::ALLOWED_EXTENSIONS)
            );
        }

        if ($file->getSize() > self::MAX_FILE_SIZE) {
            throw new \InvalidArgumentException('File size exceeds maximum allowed size of 2GB');
        }
    }

    /**
     * Get file extension, handling compound extensions like .tar.gz.
     */
    private function getFullExtension(string $filename): string
    {
        if (preg_match('/\.tar\.gz$/i', $filename)) {
            return 'tar.gz';
        }

        return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    }

    /**
     * Extract a .gz or .tar.gz file.
     */
    private function extractGz(string $filePath, string $extractDir): string
    {
        $extension = $this->getFullExtension($filePath);

        if ($extension === 'tar.gz' || $extension === 'tgz') {
            $result = Process::timeout(300)->path($extractDir)->run(['tar', '-xzf', $filePath]);

            if (! $result->successful()) {
                throw new \RuntimeException('Failed to extract tar.gz file: '.$result->errorOutput());
            }

            return $this->findSqlFile($extractDir);
        }

        // Plain .gz file - decompress using gunzip and write output to file
        $outputPath = $extractDir.'/'.preg_replace('/\.gz$/i', '', basename($filePath));

        // If no .sql extension after removing .gz, add it
        if (! str_ends_with($outputPath, '.sql')) {
            $outputPath .= '.sql';
        }

        $result = Process::timeout(300)->run(['gunzip', '-c', $filePath]);

        if (! $result->successful()) {
            throw new \RuntimeException('Failed to extract gz file: '.$result->errorOutput());
        }

        file_put_contents($outputPath, $result->output());

        return $outputPath;
    }

    /**
     * Extract a .zip file.
     */
    private function extractZip(string $filePath, string $extractDir): string
    {
        $zip = new \ZipArchive;
        $res = $zip->open($filePath);

        if ($res !== true) {
            throw new \RuntimeException('Failed to open zip file');
        }

        $zip->extractTo($extractDir);
        $zip->close();

        return $this->findSqlFile($extractDir);
    }

    /**
     * Find the first .sql file in a directory.
     */
    private function findSqlFile(string $directory): string
    {
        $sqlFiles = glob($directory.'/*.sql');

        // Also search one level deep
        if (empty($sqlFiles)) {
            $sqlFiles = glob($directory.'/*/*.sql');
        }

        if (empty($sqlFiles)) {
            throw new \RuntimeException('No SQL file found in the archive');
        }

        // Prefer the largest SQL file (likely the main dump)
        usort($sqlFiles, fn ($a, $b) => filesize($b) - filesize($a));

        return $sqlFiles[0];
    }

    /**
     * Find an available port for the MySQL container.
     */
    private function findAvailablePort(): int
    {
        $startPort = 33060;
        $endPort = 33999;

        for ($port = $startPort; $port <= $endPort; $port++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 1);
            if ($socket === false) {
                return $port;
            }
            fclose($socket);
        }

        throw new \RuntimeException('No available port found in range '.$startPort.'-'.$endPort);
    }

    /**
     * Wait for MySQL to be ready inside the container.
     */
    private function waitForMysql(string $containerName, string $password, int $maxAttempts = 30): void
    {
        for ($i = 0; $i < $maxAttempts; $i++) {
            sleep(2);

            $result = Process::timeout(10)->run([
                'docker', 'exec', $containerName, 'mysqladmin', 'ping', '-uroot', '-p'.$password, '--silent',
            ]);

            if ($result->successful()) {
                Log::info("MySQL container {$containerName} is ready after ".($i + 1).' attempts');

                return;
            }
        }

        // Clean up on failure
        Process::timeout(10)->run(['docker', 'rm', '-f', $containerName]);

        throw new \RuntimeException('MySQL container failed to start within '.$maxAttempts.' attempts');
    }

    /**
     * Import SQL dump into the container.
     */
    private function importDump(string $containerName, string $sqlPath, string $password, string $dbName): void
    {
        $result = Process::timeout(600)
            ->input(file_get_contents($sqlPath))
            ->run([
                'docker', 'exec', '-i', $containerName, 'mysql', '-uroot', '-p'.$password, $dbName,
            ]);

        if (! $result->successful()) {
            Log::error('Dump import failed', ['error' => $result->errorOutput()]);
            throw new \RuntimeException('Failed to import SQL dump: '.$result->errorOutput());
        }

        Log::info("SQL dump imported into container {$containerName}");
    }

    /**
     * Determine the host to connect to the Docker container.
     */
    private function determineHost(): string
    {
        // Check if we're running inside Docker
        if (file_exists('/.dockerenv')) {
            // Try host.docker.internal first (Docker Desktop)
            $result = Process::timeout(5)->run(['getent', 'hosts', 'host.docker.internal']);
            if ($result->successful()) {
                return 'host.docker.internal';
            }

            // Fall back to Docker gateway
            $result = Process::timeout(5)->run([
                'docker', 'network', 'inspect', 'bridge', '--format={{range .IPAM.Config}}{{.Gateway}}{{end}}',
            ]);
            if ($result->successful() && trim($result->output())) {
                return trim($result->output());
            }
        }

        return '127.0.0.1';
    }
}

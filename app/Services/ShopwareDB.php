<?php

namespace App\Services;

use Illuminate\Database\Connection;
use Illuminate\Database\Connectors\MySqlConnector;
use Illuminate\Database\MySqlConnection;

class ShopwareDB
{
    protected array $config;
    protected ?Connection $connection = null;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public static function fromMigration(\App\Models\MigrationRun $migration): static
    {
        return new static($migration->shopwareSettings());
    }

    public function connection(): Connection
    {
        if ($this->connection === null) {
            $connector = new MySqlConnector;
            $pdo = $connector->connect([
                'host' => $this->config['db_host'] ?? '127.0.0.1',
                'port' => $this->config['db_port'] ?? 3306,
                'database' => $this->config['db_database'] ?? '',
                'username' => $this->config['db_username'] ?? '',
                'password' => $this->config['db_password'] ?? '',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
            ]);
            $this->connection = new MySqlConnection($pdo, $this->config['db_database'] ?? '');
        }

        return $this->connection;
    }

    public function select(string $query, array $bindings = []): array
    {
        return $this->connection()->select($query, $bindings);
    }

    public function languageId(): string
    {
        return $this->config['language_id'] ?? '';
    }

    public function languageIdBin(): string
    {
        return hex2bin($this->languageId());
    }

    public function liveVersionId(): string
    {
        return $this->config['live_version_id'] ?? '';
    }

    public function liveVersionIdBin(): string
    {
        return hex2bin($this->liveVersionId());
    }

    public function baseUrl(): string
    {
        return rtrim($this->config['base_url'] ?? '', '/');
    }

    public function ping(): bool
    {
        try {
            $this->connection()->select('SELECT 1');

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}

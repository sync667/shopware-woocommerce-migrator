<?php

namespace App\Services;

use Illuminate\Database\Connection;
use Illuminate\Database\Connectors\MySqlConnector;
use Illuminate\Database\MySqlConnection;

class ShopwareDB
{
    protected array $config;

    protected ?Connection $connection = null;

    protected ?SSHTunnel $sshTunnel = null;

    protected ?string $shopwareVersion = null;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->shopwareVersion = $config['shopware_version'] ?? null;
    }

    public static function fromMigration(\App\Models\MigrationRun $migration): static
    {
        return new static($migration->shopwareSettings());
    }

    public function connection(): Connection
    {
        if ($this->connection === null) {
            $host = $this->config['db_host'] ?? '127.0.0.1';
            $port = $this->config['db_port'] ?? 3306;

            // Create SSH tunnel if configured
            if (! empty($this->config['ssh'])) {
                $this->sshTunnel = new SSHTunnel($this->config['ssh']);
                $port = $this->sshTunnel->connect($host, $port);
                $host = '127.0.0.1'; // Connect to tunnel on localhost
            }

            $connector = new MySqlConnector;
            $pdo = $connector->connect([
                'host' => $host,
                'port' => $port,
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

    /**
     * Get the detected Shopware major version line (e.g. '6.5', '6.6', '6.7').
     * Returns null if not yet detected.
     */
    public function shopwareVersion(): ?string
    {
        return $this->shopwareVersion;
    }

    /**
     * Check if the Shopware version is at least the given version.
     * Example: isAtLeast('6.6') returns true for 6.6 and 6.7.
     */
    public function isAtLeast(string $minVersion): bool
    {
        if ($this->shopwareVersion === null) {
            return false;
        }

        return version_compare($this->shopwareVersion, $minVersion, '>=');
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

    /**
     * Explicitly close the PDO connection and SSH tunnel.
     * Call this at the end of queue jobs to release the connection slot immediately
     * rather than waiting for PHP garbage collection.
     */
    public function disconnect(): void
    {
        if ($this->connection !== null) {
            // Explicitly release the PDO resource before nulling the connection.
            // Simply setting $this->connection = null is not sufficient in long-running
            // workers because Laravel's Connection object has internal closures/listeners
            // that create reference cycles, preventing PHP from GC-ing the PDO immediately.
            $this->connection->setPdo(null)->setReadPdo(null);
            $this->connection = null;
        }

        if ($this->sshTunnel) {
            $this->sshTunnel->disconnect();
            $this->sshTunnel = null;
        }
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}

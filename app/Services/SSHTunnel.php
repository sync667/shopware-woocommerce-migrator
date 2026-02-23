<?php

namespace App\Services;

class SSHTunnel
{
    protected array $config;

    protected ?int $localPort = null;

    protected $process = null;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Create SSH tunnel and return local port
     */
    public function connect(string $remoteHost, int $remotePort): int
    {
        // Find available local port
        $this->localPort = $this->findAvailablePort();

        $sshHost = $this->config['host'];
        $sshPort = $this->config['port'] ?? 22;
        $sshUser = $this->config['username'];

        // Build SSH tunnel command
        $command = [
            'ssh',
            '-N', // Don't execute remote command
            '-L', "{$this->localPort}:{$remoteHost}:{$remotePort}", // Local port forwarding
            '-p', (string) $sshPort,
            '-o', 'StrictHostKeyChecking=no',
            '-o', 'UserKnownHostsFile=/dev/null',
            '-o', 'ServerAliveInterval=60',
        ];

        // Add authentication
        if (! empty($this->config['key'])) {
            $command[] = '-i';
            $command[] = $this->config['key'];
        } elseif (! empty($this->config['password'])) {
            // For password authentication, we'd need sshpass
            array_unshift($command, 'sshpass', '-p', $this->config['password']);
        }

        // Add user@host
        $command[] = "{$sshUser}@{$sshHost}";

        // Start SSH tunnel in background
        $this->process = proc_open(
            implode(' ', array_map('escapeshellarg', $command)),
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes
        );

        if (! is_resource($this->process)) {
            throw new \RuntimeException('Failed to create SSH tunnel');
        }

        // Give tunnel time to establish
        sleep(2);

        // Check if tunnel is alive
        $status = proc_get_status($this->process);
        if (! $status['running']) {
            throw new \RuntimeException('SSH tunnel failed to start');
        }

        return $this->localPort;
    }

    /**
     * Close SSH tunnel
     */
    public function disconnect(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
            $this->process = null;
        }
    }

    /**
     * Get local port for tunnel
     */
    public function getLocalPort(): ?int
    {
        return $this->localPort;
    }

    /**
     * Find an available port on localhost
     */
    protected function findAvailablePort(): int
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_bind($socket, '127.0.0.1', 0);
        socket_getsockname($socket, $address, $port);
        socket_close($socket);

        return $port;
    }

    /**
     * Destructor - ensure tunnel is closed
     */
    public function __destruct()
    {
        $this->disconnect();
    }
}

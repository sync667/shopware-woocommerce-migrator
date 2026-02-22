<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Support\Facades\Log;

class WooCommerceClient
{
    protected Client $client;
    protected array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
        $baseUrl = rtrim($config['base_url'] ?? '', '/');

        $this->client = new Client([
            'base_uri' => $baseUrl . '/wp-json/wc/v3/',
            'auth' => [
                $config['consumer_key'] ?? '',
                $config['consumer_secret'] ?? '',
            ],
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'timeout' => 30,
        ]);
    }

    public static function fromMigration(\App\Models\MigrationRun $migration): static
    {
        return new static($migration->woocommerceSettings());
    }

    public function get(string $endpoint, array $query = []): array
    {
        $response = $this->client->get($endpoint, ['query' => $query]);

        return json_decode($response->getBody()->getContents(), true);
    }

    public function post(string $endpoint, array $data = []): array
    {
        $response = $this->client->post($endpoint, ['json' => $data]);

        return json_decode($response->getBody()->getContents(), true);
    }

    public function put(string $endpoint, array $data = []): array
    {
        $response = $this->client->put($endpoint, ['json' => $data]);

        return json_decode($response->getBody()->getContents(), true);
    }

    public function findExisting(string $endpoint, array $query): ?array
    {
        try {
            $results = $this->get($endpoint, $query);
            if (! empty($results) && is_array($results)) {
                return $results[0];
            }
        } catch (\Exception $e) {
            Log::debug("WooCommerce lookup failed: {$e->getMessage()}");
        }

        return null;
    }

    public function createOrFind(string $endpoint, array $data, string $lookupKey, string $lookupValue): array
    {
        try {
            return $this->post($endpoint, $data);
        } catch (ClientException $e) {
            $status = $e->getResponse()->getStatusCode();
            if (in_array($status, [400, 409])) {
                $existing = $this->findExisting($endpoint, [$lookupKey => $lookupValue]);
                if ($existing) {
                    return $existing;
                }
            }
            throw $e;
        }
    }

    public function ping(): bool
    {
        try {
            $this->get('system_status');

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}

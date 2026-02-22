<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Support\Facades\Log;

class WooCommerceClient
{
    protected Client $client;

    public function __construct()
    {
        $baseUrl = rtrim(config('services.woocommerce.base_url', env('WOO_BASE_URL', '')), '/');

        $this->client = new Client([
            'base_uri' => $baseUrl . '/wp-json/wc/v3/',
            'auth' => [
                config('services.woocommerce.consumer_key', env('WOO_CONSUMER_KEY', '')),
                config('services.woocommerce.consumer_secret', env('WOO_CONSUMER_SECRET', '')),
            ],
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'timeout' => 30,
        ]);
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

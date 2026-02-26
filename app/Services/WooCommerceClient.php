<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Support\Facades\Log;

class WooCommerceClient
{
    use WithCloudflareRetry;

    protected Client $client;

    protected array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
        $baseUrl = rtrim($config['base_url'] ?? '', '/');

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        // Merge custom headers if provided (for Zero Trust services like Cloudflare Access)
        if (! empty($config['custom_headers'])) {
            $headers = array_merge($headers, $config['custom_headers']);
        }

        $this->client = new Client([
            'handler' => static::makeRetryHandlerStack(),
            'base_uri' => $baseUrl.'/wp-json/wc/v3/',
            'auth' => [
                $config['consumer_key'] ?? '',
                $config['consumer_secret'] ?? '',
            ],
            'headers' => $headers,
            'timeout' => 30,
        ]);
    }

    public static function fromMigration(\App\Models\MigrationRun $migration): static
    {
        $config = $migration->woocommerceSettings();

        // Custom headers are stored at the WordPress level but apply to both WooCommerce and WordPress
        $wpSettings = $migration->wordpressSettings();
        if (! empty($wpSettings['custom_headers'])) {
            $config['custom_headers'] = $wpSettings['custom_headers'];
        }

        return new static($config);
    }

    public function get(string $endpoint, array $query = []): array
    {
        try {
            $response = $this->client->get($endpoint, ['query' => $query]);

            return json_decode($response->getBody()->getContents(), true) ?? [];
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            throw $this->withFullBody($e);
        }
    }

    public function post(string $endpoint, array $data = [], array $query = []): array
    {
        try {
            $options = ['json' => $data];
            if (! empty($query)) {
                $options['query'] = $query;
            }
            $response = $this->client->post($endpoint, $options);

            return json_decode($response->getBody()->getContents(), true) ?? [];
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            throw $this->withFullBody($e);
        }
    }

    /**
     * Batch-delete items by ID using the WooCommerce batch endpoint.
     * Passes force=true as a query param so it is inherited by each sub-request.
     *
     * @param  string[]  $extraQuery  Additional query params (e.g. ['reassign' => '0'])
     */
    public function batchDelete(string $endpoint, array $ids, array $extraQuery = []): void
    {
        $this->post(
            "{$endpoint}/batch",
            ['delete' => $ids],
            array_merge(['force' => 'true'], $extraQuery)
        );
    }

    public function put(string $endpoint, array $data = []): array
    {
        try {
            $response = $this->client->put($endpoint, ['json' => $data]);

            return json_decode($response->getBody()->getContents(), true) ?? [];
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            throw $this->withFullBody($e);
        }
    }

    public function delete(string $endpoint, array $query = []): array
    {
        try {
            $response = $this->client->delete($endpoint, ['query' => $query]);

            return json_decode($response->getBody()->getContents(), true) ?? [];
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            throw $this->withFullBody($e);
        }
    }

    /**
     * Re-throw a Guzzle RequestException with the full response body AND the request body
     * in the message. Guzzle truncates response bodies to 120 chars by default, and omits
     * the request body entirely — both make debugging API errors very hard.
     */
    protected function withFullBody(\GuzzleHttp\Exception\RequestException $e): \GuzzleHttp\Exception\RequestException
    {
        if (! $e->hasResponse()) {
            return $e;
        }

        $response = $e->getResponse();
        $body = (string) $response->getBody();
        $status = $response->getStatusCode();
        $method = $e->getRequest()->getMethod();
        $uri = $e->getRequest()->getUri();

        $message = "HTTP {$status} {$method} {$uri}: {$body}";

        try {
            $reqStream = $e->getRequest()->getBody();
            $reqStream->rewind();
            $requestBody = (string) $reqStream;
            if ($requestBody !== '') {
                if (strlen($requestBody) > 2000) {
                    $requestBody = substr($requestBody, 0, 2000).'... [truncated]';
                }
                $message .= "\nRequest: {$requestBody}";
            }
        } catch (\Throwable) {
            // Body unreadable — skip silently
        }

        $class = get_class($e);

        return new $class(
            $message,
            $e->getRequest(),
            $response,
            $e,
            $e->getHandlerContext()
        );
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

    /**
     * Email notification groups that fire during customer/order creation via REST API.
     * These are suppressed for the duration of a migration to avoid spamming customers
     * and admins with historical data imports.
     */
    private const MIGRATION_EMAIL_GROUPS = [
        'email_new_order',
        'email_cancelled_order',
        'email_failed_order',
        'email_customer_on_hold_order',
        'email_customer_processing_order',
        'email_customer_completed_order',
        'email_customer_refunded_order',
        'email_customer_new_account',
    ];

    /**
     * Disable WooCommerce email notifications for all migration-relevant event types.
     * Returns the previous enabled/disabled value for each group so it can be restored later.
     *
     * @return array<string, string> group_id → 'yes'|'no'
     */
    public function disableEmails(): array
    {
        $backup = [];

        foreach (self::MIGRATION_EMAIL_GROUPS as $group) {
            try {
                $setting = $this->get("settings/{$group}/enabled");
                $backup[$group] = $setting['value'] ?? 'yes';
                $this->put("settings/{$group}/enabled", ['value' => 'no']);
            } catch (\Exception) {
                // Setting may not exist (e.g. plugin not installed) — skip silently.
            }
        }

        return $backup;
    }

    /**
     * Restore WooCommerce email settings from a backup produced by disableEmails().
     *
     * @param  array<string, string>  $backup
     */
    public function restoreEmails(array $backup): void
    {
        foreach ($backup as $group => $value) {
            try {
                $this->put("settings/{$group}/enabled", ['value' => $value]);
            } catch (\Exception) {
                // Best-effort — if it fails, the admin can re-enable manually.
            }
        }
    }
}

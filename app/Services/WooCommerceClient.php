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
            'connect_timeout' => 10,
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

    /**
     * Content-type aware probe — CF Access returns 200 HTML which would
     * json_decode to [] and look like a successful empty response otherwise.
     *
     * @return array{success: bool, error?: string, details?: array{status: int, content_type: string}}
     */
    public function testApiAccess(): array
    {
        try {
            $response = $this->client->get('', ['http_errors' => true]);
            $statusCode = $response->getStatusCode();
            $contentType = $response->getHeaderLine('Content-Type');
            $body = (string) $response->getBody();

            if (stripos($contentType, 'application/json') === false) {
                return [
                    'success' => false,
                    'error' => "WC API returned non-JSON response ({$statusCode}, {$contentType}) — likely a Cloudflare/Zero Trust HTML page or WP redirect. Body starts: ".substr(trim($body), 0, 120),
                ];
            }

            $decoded = json_decode($body, true);
            if (! is_array($decoded) || (empty($decoded['namespace']) && empty($decoded['routes']) && empty($decoded['store']))) {
                return [
                    'success' => false,
                    'error' => "WC API returned unexpected JSON shape ({$statusCode}). Body: ".substr($body, 0, 200),
                ];
            }

            return [
                'success' => true,
                'details' => ['status' => $statusCode, 'content_type' => $contentType],
            ];
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $statusCode = $e->getResponse()->getStatusCode();
            $body = (string) $e->getResponse()->getBody();

            if ($statusCode === 302 || $statusCode === 403) {
                return [
                    'success' => false,
                    'error' => "Access blocked ({$statusCode}) — Cloudflare Access/Zero Trust or WP forbidding the consumer key. Configure custom headers with a Service Token if behind CF, or check the WC user's role/permissions.",
                ];
            }

            if ($statusCode === 401) {
                return [
                    'success' => false,
                    'error' => 'Authentication failed (401) — check the WooCommerce consumer key/secret pair has Read/Write permission.',
                ];
            }

            return [
                'success' => false,
                'error' => "WC API error ({$statusCode}): ".substr($body, 0, 200),
            ];
        } catch (\GuzzleHttp\Exception\TooManyRedirectsException $e) {
            return [
                'success' => false,
                'error' => 'Too many redirects — likely blocked by Cloudflare Access. Configure custom headers with a Service Token.',
            ];
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            if (stripos($message, 'cloudflare') !== false || stripos($message, 'redirect') !== false) {
                return [
                    'success' => false,
                    'error' => 'Blocked by Zero Trust/Cloudflare Access — configure custom headers with Service Token credentials',
                ];
            }

            return [
                'success' => false,
                'error' => 'Cannot connect to WooCommerce REST API: '.$message,
            ];
        }
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

    /**
     * Find an existing WooCommerce order previously imported for the given Shopware ID.
     *
     * WC core's REST API can't filter orders by arbitrary meta_key/value, so we use the
     * native `?search=` query (which searches order number among other fields) to scope
     * results, then verify the `_shopware_order_id` meta on each candidate. Returns the
     * matching WC order array or null. This is the safety net for the order-POST retry
     * scenario where a previous attempt succeeded in WC but the response never reached
     * the worker (network drop, Cloudflare 5xx after creation).
     */
    public function findOrderByShopwareId(string $shopwareOrderId, string $orderNumber): ?array
    {
        // Bail early on values that would make `?search=` return everything (or nothing).
        if ($shopwareOrderId === '' || $orderNumber === '') {
            return null;
        }

        // Paginate generously: short order numbers like "1" or "42" produce many candidate
        // matches in `?search=` (which does LIKE %term% across multiple fields). A hard
        // 20-row cap would silently miss the real match in busy stores.
        $perPage = 100;
        $maxPages = 10;

        for ($page = 1; $page <= $maxPages; $page++) {
            try {
                $candidates = $this->get('orders', [
                    'search' => $orderNumber,
                    'per_page' => $perPage,
                    'page' => $page,
                ]);
            } catch (\Exception $e) {
                Log::debug("Order idempotency lookup failed: {$e->getMessage()}");

                return null;
            }

            if (! is_array($candidates) || $candidates === []) {
                return null;
            }

            foreach ($candidates as $order) {
                foreach (($order['meta_data'] ?? []) as $meta) {
                    if (($meta['key'] ?? null) === '_shopware_order_id' && ($meta['value'] ?? null) === $shopwareOrderId) {
                        return $order;
                    }
                }
            }

            if (count($candidates) < $perPage) {
                return null;
            }
        }

        // Hit the page cap without finding a match. Log so the operator can spot the
        // case where an order with thousands of `?search=` hits silently re-POSTs.
        Log::warning("findOrderByShopwareId: exhausted {$maxPages} pages of {$perPage} for order_number '{$orderNumber}' without finding meta match for shopware_id '{$shopwareOrderId}'");

        return null;
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

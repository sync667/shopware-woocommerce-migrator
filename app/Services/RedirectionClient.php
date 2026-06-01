<?php

namespace App\Services;

use App\Models\MigrationRun;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class RedirectionClient
{
    private const PER_PAGE = 200;

    private readonly string $baseUrl;

    private readonly string $username;

    private readonly string $appPassword;

    /** @var array<string, string> */
    private readonly array $customHeaders;

    /**
     * @param  array{base_url?: string, wp_username?: string, wp_app_password?: string, custom_headers?: array<string, string>}  $config
     */
    public function __construct(array $config)
    {
        $this->baseUrl = rtrim($config['base_url'] ?? '', '/');
        $this->username = $config['wp_username'] ?? '';
        $this->appPassword = $config['wp_app_password'] ?? '';
        $this->customHeaders = $config['custom_headers'] ?? [];
    }

    public static function fromMigration(MigrationRun $migration): static
    {
        $woo = $migration->woocommerceSettings();
        $wp = $migration->wordpressSettings();

        return new static([
            'base_url' => $woo['base_url'] ?? '',
            'wp_username' => $wp['username'] ?? '',
            'wp_app_password' => $wp['app_password'] ?? '',
            'custom_headers' => $wp['custom_headers'] ?? [],
        ]);
    }

    public function isAvailable(): bool
    {
        try {
            $response = $this->request()->get($this->endpoint('group'));

            return $response->successful();
        } catch (ConnectionException) {
            return false;
        }
    }

    public function ensureGroup(string $name): int
    {
        $existing = $this->findGroupId($name);
        if ($existing !== null) {
            return $existing;
        }

        $response = $this->request()->post($this->endpoint('group'), [
            'name' => $name,
            'moduleId' => 1, // 1 = WordPress module
        ]);

        if (! $response->successful()) {
            throw new RuntimeException("Failed to create Redirection group '{$name}': HTTP {$response->status()} {$response->body()}");
        }

        // Prefer the id the plugin returned inline — list-and-match by name is
        // fragile (pagination, name normalization, race with a concurrent create).
        $id = $this->extractGroupId($response->json(), $name);
        if ($id !== null) {
            return $id;
        }

        // Fallback: re-query the list. This used to be the primary path and is
        // kept for older Redirection releases that don't echo the created group.
        $id = $this->findGroupId($name);
        if ($id === null) {
            throw new RuntimeException(
                "Created Redirection group '{$name}' but could not locate its id. "
                .'Response body: '.substr((string) $response->body(), 0, 500)
            );
        }

        return $id;
    }

    /**
     * Pull a group id out of a Redirection plugin response. The plugin has
     * shipped a few different envelopes for the create-group endpoint:
     *   - { id, name, moduleId, ... }            (flat — most modern releases)
     *   - { item: { id, name, ... } }            (wrapped)
     *   - { items: [ { id, name, ... }, ... ] }  (collection — older releases)
     *
     * @param  array<string, mixed>|null  $body
     */
    private function extractGroupId(?array $body, string $name): ?int
    {
        if (! is_array($body)) {
            return null;
        }

        if (isset($body['id']) && (($body['name'] ?? $name) === $name)) {
            return (int) $body['id'];
        }
        if (isset($body['item']['id']) && (($body['item']['name'] ?? $name) === $name)) {
            return (int) $body['item']['id'];
        }
        if (isset($body['items']) && is_array($body['items'])) {
            foreach ($body['items'] as $item) {
                if (is_array($item) && ($item['name'] ?? null) === $name && isset($item['id'])) {
                    return (int) $item['id'];
                }
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    public function loadExistingSources(int $groupId): array
    {
        $sources = [];
        $page = 1;
        // Hard cap on the loop — protects against a buggy plugin response that
        // never signals end-of-data (e.g. items present but all stripped by
        // the url-key filter on line below, leaving $sources flat while we
        // think there's more data to fetch).
        $maxPages = 1000;

        for ($i = 0; $i < $maxPages; $i++) {
            $response = $this->request()->get($this->endpoint('redirect'), [
                'filter' => ['group' => $groupId],
                'per_page' => self::PER_PAGE,
                'page' => $page,
            ]);

            if (! $response->successful()) {
                throw new RuntimeException("Failed to list Redirection rules: HTTP {$response->status()} {$response->body()}");
            }

            $items = $response->json('items', []);
            if (! is_array($items) || $items === []) {
                break;
            }
            foreach ($items as $item) {
                if (isset($item['url'])) {
                    $sources[] = (string) $item['url'];
                }
            }

            $total = (int) $response->json('total', 0);
            if (count($sources) >= $total || count($items) < self::PER_PAGE) {
                break;
            }

            $page++;
        }

        return $sources;
    }

    public function createRedirect(string $source, string $target, int $code, int $groupId): int
    {
        $response = $this->request()->post($this->endpoint('redirect'), [
            'url' => $source,
            'match_type' => 'url',
            'action_type' => 'url',
            'action_code' => $code,
            'action_data' => ['url' => $target],
            'group_id' => $groupId,
        ]);

        if (! $response->successful()) {
            $message = $response->json('message') ?? $response->body();
            throw new RuntimeException("Failed to create Redirection rule for '{$source}': HTTP {$response->status()} {$message}");
        }

        $ruleId = $this->extractRuleId($response->json(), $source);
        if ($ruleId === null || $ruleId === 0) {
            throw new RuntimeException(
                "Redirection rule for '{$source}' appeared to be created but no rule id could be extracted from the response: "
                .substr((string) $response->body(), 0, 500)
            );
        }

        return $ruleId;
    }

    /**
     * Pulls the created rule id from a Redirection plugin response. The plugin
     * has shipped a few different response envelopes across versions — handle
     * the common ones, returning null when nothing identifiable can be found.
     *
     * Refuses the "items[0] fallback" when the plugin returns the *list* of rules
     * in the group rather than the just-created one — binding a new seo_url
     * entity's woo_id to a pre-existing unrelated rule would let a later delete
     * remove someone else's redirect.
     *
     * @param  array<string, mixed>|null  $body
     */
    private function extractRuleId(?array $body, string $source): ?int
    {
        if (! is_array($body)) {
            return null;
        }

        // Shape A: { items: [ { url, id, ... } ] } — accept only when url matches.
        if (isset($body['items']) && is_array($body['items'])) {
            foreach ($body['items'] as $item) {
                if (is_array($item) && ($item['url'] ?? null) === $source && isset($item['id'])) {
                    return (int) $item['id'];
                }
            }
        }

        // Shape B: { item: { id, url, ... } } — only trust when url matches or absent.
        if (isset($body['item']['id']) && (($body['item']['url'] ?? $source) === $source)) {
            return (int) $body['item']['id'];
        }

        // Shape C: flat { id, url, ... } — only trust when url matches or absent.
        if (isset($body['id']) && (($body['url'] ?? $source) === $source)) {
            return (int) $body['id'];
        }

        return null;
    }

    private function findGroupId(string $name): ?int
    {
        $response = $this->request()->get($this->endpoint('group'), [
            'per_page' => self::PER_PAGE,
        ]);

        // Auth / permission errors should surface as exceptions so the caller doesn't
        // misdiagnose them as "group missing" and try to create one (which then also
        // fails with the same error, masking the real cause).
        if (! $response->successful()) {
            throw new RuntimeException("Failed to list Redirection groups: HTTP {$response->status()} {$response->body()}");
        }

        foreach ($response->json('items', []) as $group) {
            if (($group['name'] ?? null) === $name) {
                return (int) ($group['id'] ?? 0) ?: null;
            }
        }

        return null;
    }

    private function endpoint(string $path): string
    {
        return "{$this->baseUrl}/wp-json/redirection/v1/{$path}";
    }

    private function request(): PendingRequest
    {
        $request = Http::withBasicAuth($this->username, $this->appPassword)
            ->acceptJson()
            ->asJson()
            ->timeout(30);

        if ($this->customHeaders !== []) {
            $request = $request->withHeaders($this->customHeaders);
        }

        return $request;
    }
}

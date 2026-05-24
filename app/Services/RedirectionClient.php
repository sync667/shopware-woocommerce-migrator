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

        $id = $this->findGroupId($name);
        if ($id === null) {
            throw new RuntimeException("Created Redirection group '{$name}' but could not locate its id");
        }

        return $id;
    }

    /**
     * @return array<int, string>
     */
    public function loadExistingSources(int $groupId): array
    {
        $sources = [];
        $page = 0;

        while (true) {
            $response = $this->request()->get($this->endpoint('redirect'), [
                'filterBy' => ['group' => $groupId],
                'perPage' => self::PER_PAGE,
                'page' => $page,
            ]);

            if (! $response->successful()) {
                throw new RuntimeException("Failed to list Redirection rules: HTTP {$response->status()} {$response->body()}");
            }

            $items = $response->json('items', []);
            foreach ($items as $item) {
                if (isset($item['url'])) {
                    $sources[] = (string) $item['url'];
                }
            }

            if (count($items) < self::PER_PAGE) {
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

        $items = $response->json('items', []);
        foreach ($items as $item) {
            if (($item['url'] ?? null) === $source) {
                return (int) ($item['id'] ?? 0);
            }
        }

        $first = $items[0] ?? null;
        if ($first && isset($first['id'])) {
            return (int) $first['id'];
        }

        return 0;
    }

    private function findGroupId(string $name): ?int
    {
        $response = $this->request()->get($this->endpoint('group'), [
            'perPage' => self::PER_PAGE,
        ]);

        if (! $response->successful()) {
            return null;
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

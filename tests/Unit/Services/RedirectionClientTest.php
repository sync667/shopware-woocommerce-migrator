<?php

namespace Tests\Unit\Services;

use App\Services\RedirectionClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RedirectionClientTest extends TestCase
{
    private array $config = [
        'base_url' => 'https://woo.test',
        'wp_username' => 'admin',
        'wp_app_password' => 'secret',
    ];

    public function test_is_available_returns_true_on_200(): void
    {
        Http::fake([
            'https://woo.test/wp-json/redirection/v1/group*' => Http::response(['items' => [], 'total' => 0], 200),
        ]);

        $client = new RedirectionClient($this->config);

        $this->assertTrue($client->isAvailable());
    }

    public function test_is_available_returns_false_on_404(): void
    {
        Http::fake([
            'https://woo.test/wp-json/redirection/v1/group*' => Http::response('Not found', 404),
        ]);

        $client = new RedirectionClient($this->config);

        $this->assertFalse($client->isAvailable());
    }

    public function test_is_available_returns_false_on_connection_error(): void
    {
        Http::fake([
            'https://woo.test/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('refused'),
        ]);

        $client = new RedirectionClient($this->config);

        $this->assertFalse($client->isAvailable());
    }

    public function test_ensure_group_returns_existing_id(): void
    {
        Http::fake([
            'https://woo.test/wp-json/redirection/v1/group*' => Http::response([
                'items' => [
                    ['id' => 5, 'name' => 'Other Group'],
                    ['id' => 7, 'name' => 'Shopware Migration'],
                ],
                'total' => 2,
            ], 200),
        ]);

        $client = new RedirectionClient($this->config);

        $this->assertSame(7, $client->ensureGroup('Shopware Migration'));
    }

    public function test_ensure_group_creates_when_absent(): void
    {
        Http::fakeSequence('https://woo.test/wp-json/redirection/v1/group*')
            ->push(['items' => [['id' => 1, 'name' => 'Other']], 'total' => 1], 200)
            ->push(['id' => 42, 'name' => 'Shopware Migration'], 200)
            ->push(['items' => [['id' => 1, 'name' => 'Other'], ['id' => 42, 'name' => 'Shopware Migration']], 'total' => 2], 200);

        $client = new RedirectionClient($this->config);

        $id = $client->ensureGroup('Shopware Migration');

        $this->assertSame(42, $id);
        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && str_contains($request->url(), '/wp-json/redirection/v1/group')
                && $request['name'] === 'Shopware Migration';
        });
    }

    public function test_load_existing_sources_single_page(): void
    {
        Http::fake([
            'https://woo.test/wp-json/redirection/v1/redirect*' => Http::response([
                'items' => [
                    ['url' => '/old-product-1'],
                    ['url' => '/old-product-2'],
                ],
                'total' => 2,
            ], 200),
        ]);

        $client = new RedirectionClient($this->config);

        $sources = $client->loadExistingSources(7);

        $this->assertSame(['/old-product-1', '/old-product-2'], $sources);
    }

    public function test_load_existing_sources_follows_pagination(): void
    {
        Http::fakeSequence('https://woo.test/wp-json/redirection/v1/redirect*')
            ->push([
                'items' => array_map(fn ($i) => ['url' => "/page-{$i}"], range(1, 200)),
                'total' => 250,
            ], 200)
            ->push([
                'items' => array_map(fn ($i) => ['url' => "/page-{$i}"], range(201, 250)),
                'total' => 250,
            ], 200);

        $client = new RedirectionClient($this->config);

        $sources = $client->loadExistingSources(7);

        $this->assertCount(250, $sources);
        $this->assertSame('/page-1', $sources[0]);
        $this->assertSame('/page-250', $sources[249]);
    }

    public function test_create_redirect_posts_correct_payload(): void
    {
        Http::fake([
            'https://woo.test/wp-json/redirection/v1/redirect*' => Http::response([
                'items' => [
                    ['id' => 999, 'url' => '/old', 'action_data' => ['url' => '/new']],
                ],
                'total' => 1,
            ], 200),
        ]);

        $client = new RedirectionClient($this->config);

        $ruleId = $client->createRedirect('/old', '/new', 301, 7);

        $this->assertSame(999, $ruleId);
        Http::assertSent(function ($request) {
            if ($request->method() !== 'POST' || ! str_contains($request->url(), '/wp-json/redirection/v1/redirect')) {
                return false;
            }

            return $request['url'] === '/old'
                && $request['action_data']['url'] === '/new'
                && $request['action_type'] === 'url'
                && (int) $request['action_code'] === 301
                && (int) $request['group_id'] === 7
                && $request['match_type'] === 'url';
        });
    }

    public function test_create_redirect_throws_on_4xx(): void
    {
        Http::fake([
            'https://woo.test/wp-json/redirection/v1/redirect*' => Http::response(['message' => 'bad data'], 400),
        ]);

        $client = new RedirectionClient($this->config);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/bad data|400/i');

        $client->createRedirect('/old', '/new', 301, 7);
    }

    public function test_sends_basic_auth_header(): void
    {
        Http::fake([
            'https://woo.test/*' => Http::response(['items' => [], 'total' => 0], 200),
        ]);

        $client = new RedirectionClient($this->config);
        $client->isAvailable();

        Http::assertSent(function ($request) {
            $auth = $request->header('Authorization')[0] ?? '';
            $expected = 'Basic '.base64_encode('admin:secret');

            return $auth === $expected;
        });
    }

    public function test_sends_custom_headers_when_configured(): void
    {
        Http::fake([
            'https://woo.test/*' => Http::response(['items' => [], 'total' => 0], 200),
        ]);

        $config = $this->config + ['custom_headers' => ['CF-Access-Client-Id' => 'abc']];
        $client = new RedirectionClient($config);
        $client->isAvailable();

        Http::assertSent(fn ($request) => ($request->header('CF-Access-Client-Id')[0] ?? '') === 'abc');
    }
}

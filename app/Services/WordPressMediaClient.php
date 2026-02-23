<?php

namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

class WordPressMediaClient
{
    protected Client $client;

    protected array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
        $baseUrl = rtrim($config['base_url'] ?? '', '/');

        $clientConfig = [
            'base_uri' => $baseUrl.'/wp-json/wp/v2/',
            'auth' => [
                $config['wp_username'] ?? '',
                $config['wp_app_password'] ?? '',
            ],
            'timeout' => 60,
        ];

        // Add custom headers if provided (for Zero Trust services like Cloudflare Access)
        if (! empty($config['custom_headers'])) {
            $clientConfig['headers'] = $config['custom_headers'];
        }

        $this->client = new Client($clientConfig);
    }

    public static function fromMigration(\App\Models\MigrationRun $migration): static
    {
        $woo = $migration->woocommerceSettings();
        $wp = $migration->wordpressSettings();

        $config = [
            'base_url' => $woo['base_url'] ?? '',
            'wp_username' => $wp['username'] ?? '',
            'wp_app_password' => $wp['app_password'] ?? '',
        ];

        // Add custom headers if configured
        if (! empty($wp['custom_headers'])) {
            $config['custom_headers'] = $wp['custom_headers'];
        }

        return new static($config);
    }

    /**
     * Test if WordPress REST API is accessible
     */
    public function testApiAccess(): array
    {
        try {
            // Test authentication by getting current user
            $response = $this->client->get('users/me');
            $user = json_decode($response->getBody()->getContents(), true);

            if (empty($user['id'])) {
                return [
                    'success' => false,
                    'error' => 'API accessible but authentication failed - check username and application password',
                ];
            }

            return [
                'success' => true,
                'user' => $user['name'] ?? 'Unknown',
                'user_id' => $user['id'],
            ];
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $statusCode = $e->getResponse()->getStatusCode();

            // Detect Cloudflare Access or Zero Trust blocking
            if ($statusCode === 302 || $statusCode === 403) {
                return [
                    'success' => false,
                    'error' => "Access blocked ({$statusCode}) - likely Cloudflare Access or Zero Trust. Configure custom headers with Service Token.",
                ];
            }

            if ($statusCode === 401) {
                return [
                    'success' => false,
                    'error' => 'Authentication failed (401) - check username and application password are correct',
                ];
            }

            return [
                'success' => false,
                'error' => "API error ({$statusCode}): ".$e->getMessage(),
            ];
        } catch (\GuzzleHttp\Exception\TooManyRedirectsException $e) {
            return [
                'success' => false,
                'error' => 'Too many redirects - likely blocked by Cloudflare Access. Configure custom headers with Service Token.',
            ];
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();

            // Check for redirect or access-related errors
            if (stripos($errorMessage, 'redirect') !== false || stripos($errorMessage, 'cloudflare') !== false) {
                return [
                    'success' => false,
                    'error' => 'Blocked by Zero Trust/Cloudflare Access - configure custom headers with Service Token credentials',
                ];
            }

            return [
                'success' => false,
                'error' => 'Cannot connect to WordPress REST API: '.$errorMessage,
            ];
        }
    }

    public function upload(string $fileContents, string $filename, string $mimeType, string $title = '', string $altText = ''): ?int
    {
        try {
            $response = $this->client->post('media', [
                'headers' => [
                    'Content-Disposition' => "attachment; filename=\"{$filename}\"",
                    'Content-Type' => $mimeType,
                ],
                'body' => $fileContents,
            ]);

            $result = json_decode($response->getBody()->getContents(), true);
            $mediaId = $result['id'] ?? null;

            if ($mediaId && ($title || $altText)) {
                $updateData = [];
                if ($title) {
                    $updateData['title'] = $title;
                }
                if ($altText) {
                    $updateData['alt_text'] = $altText;
                }
                $this->client->post("media/{$mediaId}", [
                    'headers' => ['Content-Type' => 'application/json'],
                    'json' => $updateData,
                ]);
            }

            return $mediaId;
        } catch (\Exception $e) {
            Log::error("WordPress media upload failed: {$e->getMessage()}", [
                'filename' => $filename,
            ]);

            return null;
        }
    }

    /**
     * Get media details by ID
     */
    public function get(int $mediaId): ?array
    {
        try {
            $response = $this->client->get("media/{$mediaId}");

            return json_decode($response->getBody()->getContents(), true);
        } catch (\Exception $e) {
            Log::error("WordPress media fetch failed: {$e->getMessage()}", [
                'media_id' => $mediaId,
            ]);

            return null;
        }
    }
}

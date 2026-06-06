<?php

namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

class WordPressMediaClient
{
    use WithCloudflareRetry;

    protected Client $client;

    protected array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
        $baseUrl = rtrim($config['base_url'] ?? '', '/');

        $clientConfig = [
            'handler' => static::makeRetryHandlerStack(),
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
        // Probe /media not /users/me — hardening plugins often restrict /users to admin
        // and return 200 with rest_user_invalid_id, masking valid creds as auth failure.
        try {
            $response = $this->client->get('media', [
                'query' => ['per_page' => 1, 'context' => 'edit'],
                'http_errors' => true,
            ]);

            $statusCode = $response->getStatusCode();
            $body = (string) $response->getBody();
            $contentType = $response->getHeaderLine('Content-Type');

            if (stripos($contentType, 'application/json') === false) {
                return [
                    'success' => false,
                    'error' => "API returned non-JSON response ({$statusCode}, {$contentType}) — likely a Cloudflare/Zero Trust HTML page or WP redirect. Body starts: ".substr(trim($body), 0, 120),
                ];
            }

            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                return [
                    'success' => true,
                    'user' => $this->resolveAuthedUserName(),
                ];
            }

            return [
                'success' => false,
                'error' => "API returned unexpected JSON shape ({$statusCode}). Body: ".substr($body, 0, 200),
            ];
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $statusCode = $e->getResponse()->getStatusCode();
            $body = (string) $e->getResponse()->getBody();

            if ($statusCode === 302 || $statusCode === 403) {
                $detail = $this->extractRestErrorCode($body);

                return [
                    'success' => false,
                    'error' => "Access blocked ({$statusCode}".($detail ? ", {$detail}" : '').') — Cloudflare Access/Zero Trust or a WP role lacking upload_files. Configure custom headers with a Service Token if behind CF.',
                ];
            }

            if ($statusCode === 401) {
                $detail = $this->extractRestErrorCode($body);

                return [
                    'success' => false,
                    'error' => 'Authentication failed (401'.($detail ? ", {$detail}" : '').') — check the WP username (login, not email) and that the application password was pasted with its spaces.',
                ];
            }

            return [
                'success' => false,
                'error' => "API error ({$statusCode}): ".substr($body, 0, 200),
            ];
        } catch (\GuzzleHttp\Exception\TooManyRedirectsException $e) {
            return [
                'success' => false,
                'error' => 'Too many redirects - likely blocked by Cloudflare Access. Configure custom headers with Service Token.',
            ];
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();

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

    protected function resolveAuthedUserName(): string
    {
        try {
            $response = $this->client->get('users/me');
            $user = json_decode((string) $response->getBody(), true);

            return is_array($user) ? ($user['name'] ?? 'Unknown') : 'Unknown';
        } catch (\Throwable) {
            return 'Unknown';
        }
    }

    protected function extractRestErrorCode(string $body): ?string
    {
        $decoded = json_decode($body, true);
        if (! is_array($decoded)) {
            return null;
        }

        return $decoded['code'] ?? null;
    }

    public function upload(string $fileContents, string $filename, string $mimeType, string $title = '', string $altText = ''): ?int
    {
        try {
            // WordPress uses the Content-Disposition filename for MIME/type detection.
            // Non-ASCII characters (spaces, Polish letters, etc.) cause wp_check_filetype_and_ext()
            // to fail with rest_upload_sideload_error, so we sanitize to ASCII-safe chars only.
            $safeFilename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
            $safeFilename = ltrim($safeFilename, '._') ?: 'image.jpg';

            $response = $this->client->post('media', [
                'headers' => [
                    'Content-Disposition' => "attachment; filename=\"{$safeFilename}\"",
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
     * Get a page of WordPress pages
     */
    public function getPages(int $page = 1, int $perPage = 100): array
    {
        try {
            $response = $this->client->get('pages', [
                'query' => [
                    'per_page' => $perPage,
                    'page' => $page,
                    'status' => 'any',
                ],
            ]);

            return json_decode($response->getBody()->getContents(), true) ?? [];
        } catch (\Exception $e) {
            Log::error("WordPress pages fetch failed: {$e->getMessage()}");

            return [];
        }
    }

    /**
     * Permanently delete a WordPress page
     */
    public function deletePage(int $pageId): void
    {
        $this->client->delete("pages/{$pageId}", [
            'query' => ['force' => true],
        ]);
    }

    /**
     * Permanently delete a WordPress comment (e.g. an orphaned product review)
     */
    public function deleteComment(int $commentId): void
    {
        $this->client->delete("comments/{$commentId}", [
            'query' => ['force' => true],
        ]);
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

    /**
     * List a page of media items
     */
    public function listMedia(int $page = 1, int $perPage = 100): array
    {
        try {
            $response = $this->client->get('media', [
                'query' => [
                    'per_page' => $perPage,
                    'page' => $page,
                ],
            ]);

            return json_decode($response->getBody()->getContents(), true) ?? [];
        } catch (\Exception $e) {
            Log::error("WordPress media list failed: {$e->getMessage()}");

            return [];
        }
    }

    /**
     * Permanently delete a WordPress media attachment
     */
    public function deleteMedia(int $mediaId): void
    {
        $this->client->delete("media/{$mediaId}", [
            'query' => ['force' => true],
        ]);
    }

    /**
     * Batch-delete media attachments using the WordPress REST batch API (WP 5.6+).
     * WordPress limits each batch request to 25 sub-requests by default, so the
     * given IDs are chunked accordingly and multiple batch calls are made.
     *
     * @param  int[]  $ids
     * @return array{deleted: int, failed: int}
     */
    /**
     * Batch-delete media attachments using the WordPress REST batch API (WP 5.6+).
     * WordPress limits each batch request to 25 sub-requests by default, so the
     * given IDs are chunked accordingly and multiple batch calls are made.
     * Falls back to individual DELETE calls if the batch endpoint is unavailable (404).
     *
     * @param  int[]  $ids
     * @return array{deleted: int, failed: int}
     */
    public function batchDeleteMedia(array $ids, int $chunkSize = 25): array
    {
        $batchUrl = rtrim($this->config['base_url'] ?? '', '/').'/wp-json/batch/v1';
        $deleted = 0;
        $failed = 0;
        $batchSupported = true;

        foreach (array_chunk($ids, $chunkSize) as $chunk) {
            if (! $batchSupported) {
                // Batch API unavailable — fall back to individual deletes for remaining chunks
                foreach ($chunk as $id) {
                    try {
                        $this->deleteMedia($id);
                        $deleted++;
                    } catch (\Exception $e) {
                        $failed++;
                    }
                }

                continue;
            }

            $requests = array_map(
                fn ($id) => ['method' => 'DELETE', 'path' => '/wp/v2/media/'.$id.'?force=true'],
                $chunk
            );

            try {
                $response = $this->client->post($batchUrl, [
                    'json' => ['validation' => 'normal', 'requests' => $requests],
                ]);

                $result = json_decode($response->getBody()->getContents(), true);
                foreach ($result['responses'] ?? [] as $res) {
                    if (($res['status'] ?? 500) < 300) {
                        $deleted++;
                    } else {
                        $failed++;
                    }
                }
            } catch (\GuzzleHttp\Exception\ClientException $e) {
                if ($e->getResponse()->getStatusCode() === 404) {
                    // Batch endpoint not available — switch to individual deletes
                    $batchSupported = false;
                    Log::info('WordPress batch API not available, falling back to individual media deletes');
                    foreach ($chunk as $id) {
                        try {
                            $this->deleteMedia($id);
                            $deleted++;
                        } catch (\Exception $e2) {
                            $failed++;
                        }
                    }
                } else {
                    $failed += count($chunk);
                    Log::error('WordPress batch media delete failed: '.$e->getMessage());
                }
            } catch (\Exception $e) {
                $failed += count($chunk);
                Log::error('WordPress batch media delete failed: '.$e->getMessage());
            }
        }

        return ['deleted' => $deleted, 'failed' => $failed];
    }
}

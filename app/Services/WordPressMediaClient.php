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

        $this->client = new Client([
            'base_uri' => $baseUrl.'/wp-json/wp/v2/',
            'auth' => [
                $config['wp_username'] ?? '',
                $config['wp_app_password'] ?? '',
            ],
            'timeout' => 60,
        ]);
    }

    public static function fromMigration(\App\Models\MigrationRun $migration): static
    {
        $woo = $migration->woocommerceSettings();
        $wp = $migration->wordpressSettings();

        return new static([
            'base_url' => $woo['base_url'] ?? '',
            'wp_username' => $wp['username'] ?? '',
            'wp_app_password' => $wp['app_password'] ?? '',
        ]);
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
}

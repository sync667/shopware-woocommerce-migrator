<?php

namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

class WordPressMediaClient
{
    protected Client $client;

    public function __construct()
    {
        $baseUrl = rtrim(config('services.woocommerce.base_url', env('WOO_BASE_URL', '')), '/');

        $this->client = new Client([
            'base_uri' => $baseUrl . '/wp-json/wp/v2/',
            'auth' => [
                env('WP_USERNAME', ''),
                env('WP_APP_PASSWORD', ''),
            ],
            'timeout' => 60,
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

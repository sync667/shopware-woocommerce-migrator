<?php

namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

class ImageMigrator
{
    protected Client $httpClient;
    protected WordPressMediaClient $wpMedia;

    public function __construct(WordPressMediaClient $wpMedia)
    {
        $this->wpMedia = $wpMedia;
        $this->httpClient = new Client(['timeout' => 30]);
    }

    public function migrate(string $imageUrl, string $filename, string $title = '', string $altText = ''): ?int
    {
        try {
            $response = $this->httpClient->get($imageUrl);
            $contents = $response->getBody()->getContents();
            $mimeType = $this->guessMimeType($filename);

            return $this->wpMedia->upload($contents, $filename, $mimeType, $title, $altText);
        } catch (\Exception $e) {
            Log::error("Image migration failed: {$e->getMessage()}", [
                'url' => $imageUrl,
                'filename' => $filename,
            ]);

            return null;
        }
    }

    public function buildShopwareMediaUrl(string $path, string $fileName, string $extension): string
    {
        $baseUrl = rtrim(config('shopware.base_url'), '/');

        return "{$baseUrl}/media/{$path}/{$fileName}.{$extension}";
    }

    protected function guessMimeType(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            default => 'application/octet-stream',
        };
    }
}

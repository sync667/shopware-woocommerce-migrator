<?php

namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

class ImageMigrator
{
    protected Client $httpClient;

    protected WordPressMediaClient $wpMedia;

    protected string $shopwareBaseUrl;

    public function __construct(WordPressMediaClient $wpMedia, string $shopwareBaseUrl = '')
    {
        $this->wpMedia = $wpMedia;
        $this->shopwareBaseUrl = rtrim($shopwareBaseUrl, '/');
        $this->httpClient = new Client(['timeout' => 30]);
    }

    public static function fromMigration(\App\Models\MigrationRun $migration): static
    {
        $wpMedia = WordPressMediaClient::fromMigration($migration);
        $shopwareBaseUrl = $migration->setting('shopware.base_url', '');

        return new static($wpMedia, $shopwareBaseUrl);
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
        return "{$this->shopwareBaseUrl}/media/{$path}/{$fileName}.{$extension}";
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

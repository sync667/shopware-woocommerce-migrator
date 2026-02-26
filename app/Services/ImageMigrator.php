<?php

namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

class ImageMigrator
{
    protected Client $httpClient;

    protected WordPressMediaClient $wpMedia;

    protected string $shopwareBaseUrl;

    public function __construct(WordPressMediaClient $wpMedia, string $shopwareBaseUrl = '', array $customHeaders = [])
    {
        $this->wpMedia = $wpMedia;
        $this->shopwareBaseUrl = rtrim($shopwareBaseUrl, '/');

        $headers = array_merge([
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept' => 'image/webp,image/apng,image/*,*/*;q=0.8',
        ], $customHeaders);

        $this->httpClient = new Client([
            'timeout' => 30,
            'headers' => $headers,
        ]);
    }

    public static function fromMigration(\App\Models\MigrationRun $migration): static
    {
        $wpMedia = WordPressMediaClient::fromMigration($migration);
        $shopwareBaseUrl = $migration->setting('shopware.base_url', '');
        $customHeaders = $migration->setting('shopware.custom_headers', []);

        return new static($wpMedia, $shopwareBaseUrl, is_array($customHeaders) ? $customHeaders : []);
    }

    public function migrate(string $imageUrl, string $filename, string $title = '', string $altText = ''): ?int
    {
        try {
            $response = $this->httpClient->get($imageUrl);
            $contents = $response->getBody()->getContents();

            // Detect MIME type from actual binary content, not from the filename.
            // WordPress wp_check_filetype_and_ext() validates binary content via finfo,
            // so uploading HTML (soft-404) or a JPEG named .png causes rest_upload_sideload_error.
            $mimeType = $this->detectMimeType($contents);

            if (! $mimeType || ! str_starts_with($mimeType, 'image/')) {
                Log::warning("Skipping non-image content for {$filename}", [
                    'url' => $imageUrl,
                    'detected_mime' => $mimeType ?? 'unknown',
                ]);

                return null;
            }

            // Align the filename extension with the actual content type so WP accepts it.
            $filename = $this->alignExtension($filename, $mimeType);

            return $this->wpMedia->upload($contents, $filename, $mimeType, $title, $altText);
        } catch (\Exception $e) {
            Log::error("Image migration failed: {$e->getMessage()}", [
                'url' => $imageUrl,
                'filename' => $filename,
            ]);

            return null;
        }
    }

    public function buildShopwareMediaUrl(string $mediaId, string $fileName, string $extension, ?int $uploadedAt = null): string
    {
        // Shopware 6 IdPathnameStrategy derives the subdirectory from md5($media->getId()),
        // where getId() returns the 32-char lowercase hex UUID (no dashes).
        // The hash is split into 3 x 2-char pairs; 'ad' is replaced with 'g0' to avoid ad-blockers.
        $blacklist = ['ad' => 'g0'];
        $hash = md5($mediaId);
        $slices = [substr($hash, 0, 2), substr($hash, 2, 2), substr($hash, 4, 2)];
        $slices = array_map(fn (string $s) => $blacklist[$s] ?? $s, $slices);
        $path = implode('/', $slices);

        if ($uploadedAt !== null) {
            $path .= "/{$uploadedAt}";
        }

        return "{$this->shopwareBaseUrl}/media/{$path}/".rawurlencode($fileName).".{$extension}";
    }

    /**
     * Migrate image from URL (for inline images in content)
     */
    public function migrateFromUrl(string $imageUrl, string $altText = ''): ?int
    {
        // Extract filename from URL
        $filename = basename(parse_url($imageUrl, PHP_URL_PATH));

        if (empty($filename)) {
            $filename = 'image-'.md5($imageUrl).'.jpg';
        }

        return $this->migrate($imageUrl, $filename, '', $altText);
    }

    /**
     * Get WordPress media URL from media ID
     */
    public function getWordPressMediaUrl(int $mediaId): ?string
    {
        try {
            $media = $this->wpMedia->get($mediaId);

            return $media['source_url'] ?? null;
        } catch (\Exception $e) {
            Log::error("Failed to get WordPress media URL: {$e->getMessage()}", [
                'media_id' => $mediaId,
            ]);

            return null;
        }
    }

    /**
     * Detect MIME type from binary content using finfo.
     * Returns null if the content is empty or undetectable.
     */
    protected function detectMimeType(string $contents): ?string
    {
        if (empty($contents)) {
            return null;
        }

        $detected = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents);

        return $detected ?: null;
    }

    /**
     * Ensure the filename extension matches the actual detected MIME type.
     * Prevents WordPress from rejecting a JPEG file named .png, etc.
     */
    protected function alignExtension(string $filename, string $mimeType): string
    {
        $extByMime = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
        ];

        $correctExt = $extByMime[$mimeType] ?? null;
        if (! $correctExt) {
            return $filename;
        }

        $currentExt = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($currentExt === $correctExt || ($currentExt === 'jpeg' && $correctExt === 'jpg')) {
            return $filename;
        }

        return pathinfo($filename, PATHINFO_FILENAME).'.'.$correctExt;
    }
}

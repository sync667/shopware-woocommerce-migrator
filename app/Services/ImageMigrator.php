<?php

namespace App\Services;

use App\Models\MigrationEntity;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

class ImageMigrator
{
    protected Client $httpClient;

    protected WordPressMediaClient $wpMedia;

    protected string $shopwareBaseUrl;

    protected ?int $migrationId;

    public function __construct(
        WordPressMediaClient $wpMedia,
        string $shopwareBaseUrl = '',
        array $customHeaders = [],
        ?int $migrationId = null,
    ) {
        $this->wpMedia = $wpMedia;
        $this->shopwareBaseUrl = rtrim($shopwareBaseUrl, '/');
        $this->migrationId = $migrationId;

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

        return new static(
            $wpMedia,
            $shopwareBaseUrl,
            is_array($customHeaders) ? $customHeaders : [],
            $migration->id,
        );
    }

    public function migrate(string $imageUrl, string $filename, string $title = '', string $altText = '', ?string $shopwareMediaId = null): ?int
    {
        // Skip-and-reuse path: if this Shopware media was already uploaded by a previous
        // migration of this tool (any run), the operator most likely doesn't want a
        // duplicate copy in the WP media library. Verify the WP attachment still exists
        // before reusing — operator may have cleaned WP manually since the last run.
        if ($shopwareMediaId !== null && $shopwareMediaId !== '') {
            $existing = $this->findPreviouslyUploadedAttachmentId($shopwareMediaId);
            if ($existing !== null) {
                $this->recordMediaMapping($shopwareMediaId, $existing);

                return $existing;
            }
        }

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

            $uploadedId = $this->wpMedia->upload($contents, $filename, $mimeType, $title, $altText);

            if ($uploadedId !== null && $shopwareMediaId !== null && $shopwareMediaId !== '') {
                $this->recordMediaMapping($shopwareMediaId, $uploadedId);
            }

            return $uploadedId;
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
     * Returns a previously-uploaded WP attachment id for this Shopware media UUID, or
     * null when none exists. Verifies the attachment is still present in WP — operator
     * may have manually deleted it — so we don't return a dangling pointer.
     */
    protected function findPreviouslyUploadedAttachmentId(string $shopwareMediaId): ?int
    {
        try {
            $rows = MigrationEntity::query()
                ->where('entity_type', 'media')
                ->where('shopware_id', $shopwareMediaId)
                ->where('status', 'success')
                ->whereNotNull('woo_id')
                ->orderByDesc('id')
                ->limit(5)
                ->get(['woo_id']);
        } catch (\Throwable $e) {
            Log::debug("media-reuse lookup skipped: {$e->getMessage()}");

            return null;
        }

        foreach ($rows as $row) {
            $wooId = (int) $row->woo_id;
            if ($wooId <= 0) {
                continue;
            }
            try {
                $existing = $this->wpMedia->get($wooId);
                if (! empty($existing['id'])) {
                    return (int) $existing['id'];
                }
            } catch (\Throwable) {
                // Attachment is gone — try the next candidate.
            }
        }

        return null;
    }

    /**
     * Persist the (shopware media id → WP attachment id) mapping so future migrations
     * can reuse the upload and the cleanup job knows the attachment is ours.
     */
    protected function recordMediaMapping(string $shopwareMediaId, int $wooAttachmentId): void
    {
        if ($this->migrationId === null) {
            return;
        }

        try {
            MigrationEntity::updateOrCreate(
                [
                    'migration_id' => $this->migrationId,
                    'entity_type' => 'media',
                    'shopware_id' => $shopwareMediaId,
                ],
                [
                    'woo_id' => $wooAttachmentId,
                    'status' => 'success',
                ]
            );
        } catch (\Throwable $e) {
            Log::debug("media-state write skipped: {$e->getMessage()}");
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

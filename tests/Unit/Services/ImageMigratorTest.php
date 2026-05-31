<?php

namespace Tests\Unit\Services;

use App\Services\ImageMigrator;
use App\Services\WordPressMediaClient;
use PHPUnit\Framework\TestCase;

class ImageMigratorTest extends TestCase
{
    public function test_builds_shopware_media_url_from_hashed_id_path(): void
    {
        $wpMedia = $this->createMock(WordPressMediaClient::class);
        $migrator = new ImageMigrator($wpMedia, 'https://shop.example.com');

        // md5('0123456789abcdef0123456789abcdef') = 8516ac99... → '85/16/ac'
        $url = $migrator->buildShopwareMediaUrl(
            '0123456789abcdef0123456789abcdef',
            'test-image',
            'jpg'
        );

        $this->assertSame(
            'https://shop.example.com/media/85/16/ac/test-image.jpg',
            $url
        );
    }

    public function test_builds_url_with_trailing_slash_in_base(): void
    {
        $wpMedia = $this->createMock(WordPressMediaClient::class);
        $migrator = new ImageMigrator($wpMedia, 'https://shop.example.com/');

        $url = $migrator->buildShopwareMediaUrl('0123456789abcdef0123456789abcdef', 'file', 'png');

        $this->assertSame('https://shop.example.com/media/85/16/ac/file.png', $url);
    }

    public function test_appends_uploaded_at_segment_when_provided(): void
    {
        $wpMedia = $this->createMock(WordPressMediaClient::class);
        $migrator = new ImageMigrator($wpMedia, 'https://shop.example.com');

        $url = $migrator->buildShopwareMediaUrl(
            '0123456789abcdef0123456789abcdef',
            'file',
            'jpg',
            1700000000
        );

        $this->assertSame('https://shop.example.com/media/85/16/ac/1700000000/file.jpg', $url);
    }

    public function test_ad_prefix_is_rewritten_to_g0_for_adblockers(): void
    {
        $wpMedia = $this->createMock(WordPressMediaClient::class);
        $migrator = new ImageMigrator($wpMedia, 'https://shop.example.com');

        // md5('0000000000000000000000000000008a') = ad17b8... → first pair 'ad' replaced with 'g0'.
        $url = $migrator->buildShopwareMediaUrl(
            '0000000000000000000000000000008a',
            'banner',
            'jpg'
        );

        $this->assertStringStartsWith('https://shop.example.com/media/g0/17/b8/', $url);
    }

    public function test_url_encodes_filename(): void
    {
        $wpMedia = $this->createMock(WordPressMediaClient::class);
        $migrator = new ImageMigrator($wpMedia, 'https://shop.example.com');

        $url = $migrator->buildShopwareMediaUrl(
            '0123456789abcdef0123456789abcdef',
            'file with spaces & symbols',
            'jpg'
        );

        $this->assertStringContainsString('/file%20with%20spaces%20%26%20symbols.jpg', $url);
    }

    public function test_align_extension_corrects_mismatched_extension(): void
    {
        $wpMedia = $this->createMock(WordPressMediaClient::class);
        $migrator = new ImageMigrator($wpMedia, 'https://shop.example.com');

        $reflection = new \ReflectionMethod($migrator, 'alignExtension');
        $reflection->setAccessible(true);

        $this->assertSame('photo.jpg', $reflection->invoke($migrator, 'photo.png', 'image/jpeg'));
        $this->assertSame('photo.png', $reflection->invoke($migrator, 'photo.jpg', 'image/png'));
        $this->assertSame('icon.svg', $reflection->invoke($migrator, 'icon.png', 'image/svg+xml'));
    }

    public function test_align_extension_keeps_jpeg_variant_as_is(): void
    {
        $wpMedia = $this->createMock(WordPressMediaClient::class);
        $migrator = new ImageMigrator($wpMedia, 'https://shop.example.com');

        $reflection = new \ReflectionMethod($migrator, 'alignExtension');
        $reflection->setAccessible(true);

        $this->assertSame('photo.jpeg', $reflection->invoke($migrator, 'photo.jpeg', 'image/jpeg'));
        $this->assertSame('photo.jpg', $reflection->invoke($migrator, 'photo.jpg', 'image/jpeg'));
    }

    public function test_align_extension_leaves_unknown_mime_alone(): void
    {
        $wpMedia = $this->createMock(WordPressMediaClient::class);
        $migrator = new ImageMigrator($wpMedia, 'https://shop.example.com');

        $reflection = new \ReflectionMethod($migrator, 'alignExtension');
        $reflection->setAccessible(true);

        $this->assertSame('file.xyz', $reflection->invoke($migrator, 'file.xyz', 'application/octet-stream'));
    }

    public function test_detect_mime_type_returns_null_for_empty_content(): void
    {
        $wpMedia = $this->createMock(WordPressMediaClient::class);
        $migrator = new ImageMigrator($wpMedia, 'https://shop.example.com');

        $reflection = new \ReflectionMethod($migrator, 'detectMimeType');
        $reflection->setAccessible(true);

        $this->assertNull($reflection->invoke($migrator, ''));
    }

    public function test_detect_mime_type_identifies_real_png(): void
    {
        $wpMedia = $this->createMock(WordPressMediaClient::class);
        $migrator = new ImageMigrator($wpMedia, 'https://shop.example.com');

        $reflection = new \ReflectionMethod($migrator, 'detectMimeType');
        $reflection->setAccessible(true);

        // Minimal valid 1x1 transparent PNG (67 bytes) — base64 form embedded so the test
        // has no filesystem dependency. finfo recognizes this as image/png.
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='
        );

        $this->assertSame('image/png', $reflection->invoke($migrator, $png));
    }
}

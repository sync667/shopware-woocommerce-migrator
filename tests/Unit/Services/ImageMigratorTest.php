<?php

namespace Tests\Unit\Services;

use App\Services\ImageMigrator;
use App\Services\WordPressMediaClient;
use PHPUnit\Framework\TestCase;

class ImageMigratorTest extends TestCase
{
    public function test_builds_shopware_media_url(): void
    {
        $wpMedia = $this->createMock(WordPressMediaClient::class);
        $migrator = new ImageMigrator($wpMedia, 'https://shop.example.com');

        $url = $migrator->buildShopwareMediaUrl(
            'product/images',
            'test-image',
            'jpg'
        );

        $this->assertEquals(
            'https://shop.example.com/media/product/images/test-image.jpg',
            $url
        );
    }

    public function test_builds_url_with_trailing_slash_in_base(): void
    {
        $wpMedia = $this->createMock(WordPressMediaClient::class);
        $migrator = new ImageMigrator($wpMedia, 'https://shop.example.com/');

        $url = $migrator->buildShopwareMediaUrl('media', 'file', 'png');

        $this->assertEquals('https://shop.example.com/media/media/file.png', $url);
    }

    public function test_mime_type_guessing_via_reflection(): void
    {
        $wpMedia = $this->createMock(WordPressMediaClient::class);
        $migrator = new ImageMigrator($wpMedia, 'https://shop.example.com');

        $reflection = new \ReflectionMethod($migrator, 'guessMimeType');
        $reflection->setAccessible(true);

        $this->assertEquals('image/jpeg', $reflection->invoke($migrator, 'photo.jpg'));
        $this->assertEquals('image/jpeg', $reflection->invoke($migrator, 'photo.jpeg'));
        $this->assertEquals('image/png', $reflection->invoke($migrator, 'logo.png'));
        $this->assertEquals('image/gif', $reflection->invoke($migrator, 'anim.gif'));
        $this->assertEquals('image/webp', $reflection->invoke($migrator, 'modern.webp'));
        $this->assertEquals('image/svg+xml', $reflection->invoke($migrator, 'icon.svg'));
        $this->assertEquals('application/octet-stream', $reflection->invoke($migrator, 'file.xyz'));
    }
}

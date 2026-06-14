<?php

namespace Tests\Feature\Jobs;

use App\Jobs\MigrateProductBatchJob;
use PHPUnit\Framework\TestCase;

class MigrateProductBatchJobTest extends TestCase
{
    public function test_render_video_slots_empty_returns_empty_string(): void
    {
        $this->assertSame('', MigrateProductBatchJob::renderVideoSlots([]));
    }

    public function test_render_video_slots_emits_youtube_wp_embed_block(): void
    {
        $slot = (object) [
            'type' => 'youtube-video',
            'config' => json_encode(['videoID' => ['source' => 'static', 'value' => 'abc123']]),
        ];

        $out = MigrateProductBatchJob::renderVideoSlots([$slot]);

        $this->assertStringContainsString('<!-- wp:embed', $out);
        $this->assertStringContainsString('<!-- /wp:embed -->', $out);
        $this->assertStringContainsString('"providerNameSlug":"youtube"', $out);
        $this->assertStringContainsString('"url":"https://www.youtube.com/watch?v=abc123"', $out);
        $this->assertStringContainsString('https://www.youtube.com/watch?v=abc123', $out);
        $this->assertStringContainsString('wp-block-embed-youtube', $out);
        $this->assertStringContainsString('is-provider-youtube', $out);
        $this->assertStringContainsString('wp-embed-aspect-16-9', $out);
    }

    public function test_render_video_slots_emits_vimeo_wp_embed_block(): void
    {
        $slot = (object) [
            'type' => 'vimeo-video',
            'config' => json_encode(['videoID' => ['source' => 'static', 'value' => '76979871']]),
        ];

        $out = MigrateProductBatchJob::renderVideoSlots([$slot]);

        $this->assertStringContainsString('"providerNameSlug":"vimeo"', $out);
        $this->assertStringContainsString('"url":"https://vimeo.com/76979871"', $out);
        $this->assertStringContainsString('wp-block-embed-vimeo', $out);
        $this->assertStringContainsString('is-provider-vimeo', $out);
    }

    public function test_render_video_slots_skips_missing_video_id(): void
    {
        $slots = [
            (object) ['type' => 'youtube-video', 'config' => json_encode(['videoID' => ['value' => '']])],
            (object) ['type' => 'youtube-video', 'config' => json_encode(['videoID' => ['value' => null]])],
            (object) ['type' => 'youtube-video', 'config' => json_encode(['something' => 'else'])],
        ];

        $this->assertSame('', MigrateProductBatchJob::renderVideoSlots($slots));
    }

    public function test_render_video_slots_skips_malformed_config_json(): void
    {
        $slot = (object) ['type' => 'youtube-video', 'config' => '{not valid json'];

        $this->assertSame('', MigrateProductBatchJob::renderVideoSlots([$slot]));
    }

    public function test_render_video_slots_concatenates_multiple_videos(): void
    {
        $slots = [
            (object) ['type' => 'youtube-video', 'config' => json_encode(['videoID' => ['value' => 'aaa']])],
            (object) ['type' => 'vimeo-video', 'config' => json_encode(['videoID' => ['value' => 'bbb']])],
        ];

        $out = MigrateProductBatchJob::renderVideoSlots($slots);

        $this->assertSame(2, substr_count($out, '<!-- wp:embed'));
        $this->assertSame(2, substr_count($out, '<!-- /wp:embed -->'));
        $this->assertStringContainsString('watch?v=aaa', $out);
        $this->assertStringContainsString('vimeo.com/bbb', $out);
    }

    public function test_render_video_slots_escapes_html_in_video_id(): void
    {
        $slot = (object) [
            'type' => 'youtube-video',
            'config' => json_encode(['videoID' => ['value' => 'abc"><script>x()</script>']]),
        ];

        $out = MigrateProductBatchJob::renderVideoSlots([$slot]);

        $this->assertStringNotContainsString('<script>', $out);
        $this->assertStringContainsString('&quot;', $out);
    }

    public function test_render_video_slots_unknown_type_defaults_to_youtube_shape(): void
    {
        $slot = (object) [
            'type' => 'mystery-video',
            'config' => json_encode(['videoID' => ['value' => 'xyz']]),
        ];

        $out = MigrateProductBatchJob::renderVideoSlots([$slot]);

        $this->assertStringContainsString('"providerNameSlug":"youtube"', $out);
        $this->assertStringContainsString('watch?v=xyz', $out);
    }
}

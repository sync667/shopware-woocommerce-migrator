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

    public function test_render_video_slots_per_product_override_replaces_layout_default(): void
    {
        $slot = (object) [
            'slot_id' => 'slot-1',
            'type' => 'youtube-video',
            'config' => json_encode(['videoID' => ['source' => 'static', 'value' => 'layoutDefault']]),
        ];

        $overrides = ['slot-1' => ['videoID' => ['source' => 'static', 'value' => 'productOwnVideo']]];

        $out = MigrateProductBatchJob::renderVideoSlots([$slot], $overrides);

        $this->assertStringContainsString('watch?v=productOwnVideo', $out);
        $this->assertStringNotContainsString('layoutDefault', $out);
    }

    public function test_render_video_slots_uses_layout_default_when_no_override_for_slot(): void
    {
        $slot = (object) [
            'slot_id' => 'slot-1',
            'type' => 'youtube-video',
            'config' => json_encode(['videoID' => ['source' => 'static', 'value' => 'layoutDefault']]),
        ];

        $overrides = ['some-other-slot' => ['videoID' => ['source' => 'static', 'value' => 'unrelated']]];

        $out = MigrateProductBatchJob::renderVideoSlots([$slot], $overrides);

        $this->assertStringContainsString('watch?v=layoutDefault', $out);
        $this->assertStringNotContainsString('unrelated', $out);
    }

    public function test_render_video_slots_override_clearing_video_skips_slot(): void
    {
        $slot = (object) [
            'slot_id' => 'slot-1',
            'type' => 'youtube-video',
            'config' => json_encode(['videoID' => ['source' => 'static', 'value' => 'layoutDefault']]),
        ];

        $overrides = ['slot-1' => ['videoID' => ['source' => 'static', 'value' => null]]];

        $out = MigrateProductBatchJob::renderVideoSlots([$slot], $overrides);

        $this->assertSame('', $out);
    }

    public function test_resolve_layout_image_media_override_beats_default_and_preserves_order(): void
    {
        $slots = [
            (object) ['slot_id' => 's1', 'media' => 'defaultA'],
            (object) ['slot_id' => 's2', 'media' => 'defaultB'],
        ];
        $overrides = ['s1' => ['media' => ['value' => 'overrideA']]];

        $ids = MigrateProductBatchJob::resolveLayoutImageMediaIds($slots, $overrides, []);

        $this->assertSame(['overrideA', 'defaultB'], $ids);
    }

    public function test_resolve_layout_image_media_skips_gallery_and_cover(): void
    {
        $slots = [
            (object) ['slot_id' => 's1', 'media' => 'inGallery'],
            (object) ['slot_id' => 's2', 'media' => 'uniqueImg'],
        ];

        $ids = MigrateProductBatchJob::resolveLayoutImageMediaIds($slots, [], ['ingallery']);

        $this->assertSame(['uniqueImg'], $ids);
    }

    public function test_resolve_layout_image_media_null_override_clears_slot_no_default_fallback(): void
    {
        // Product explicitly cleared the image (slot_config media.value = null) — must NOT
        // fall back to the layout default.
        $slots = [
            (object) ['slot_id' => 's1', 'media' => 'layoutDefault'],
        ];
        $overrides = ['s1' => ['media' => ['source' => 'static', 'value' => null]]];

        $ids = MigrateProductBatchJob::resolveLayoutImageMediaIds($slots, $overrides, []);

        $this->assertSame([], $ids);
    }

    public function test_resolve_layout_image_media_uses_default_when_no_override_entry(): void
    {
        // No slot_config entry for the slot at all → layout default applies.
        $slots = [
            (object) ['slot_id' => 's1', 'media' => 'layoutDefault'],
        ];

        $ids = MigrateProductBatchJob::resolveLayoutImageMediaIds($slots, ['other' => ['media' => ['value' => 'x']]], []);

        $this->assertSame(['layoutDefault'], $ids);
    }

    public function test_resolve_layout_image_media_skips_null_and_dedupes_within_product(): void
    {
        $slots = [
            (object) ['slot_id' => 's1', 'media' => 'imgX'],
            (object) ['slot_id' => 's2', 'media' => null],
            (object) ['slot_id' => 's3', 'media' => 'imgX'],
        ];

        $ids = MigrateProductBatchJob::resolveLayoutImageMediaIds($slots, [], []);

        $this->assertSame(['imgX'], $ids);
    }

    public function test_render_layout_images_block_wraps_markers_and_emits_wp_image(): void
    {
        $out = MigrateProductBatchJob::renderLayoutImagesBlock([
            ['id' => 12, 'url' => 'https://wp/a.jpg', 'alt' => 'Alpha'],
            ['id' => 34, 'url' => 'https://wp/b.png', 'alt' => ''],
        ]);

        $this->assertStringContainsString('<!-- sw:layout-images:start -->', $out);
        $this->assertStringContainsString('<!-- sw:layout-images:end -->', $out);
        $this->assertSame(2, substr_count($out, '<!-- wp:image'));
        $this->assertStringContainsString('"id":12', $out);
        $this->assertStringContainsString('https://wp/a.jpg', $out);
        $this->assertStringContainsString('wp-image-34', $out);
        $this->assertStringContainsString('alt="Alpha"', $out);
    }

    public function test_render_layout_images_block_empty_returns_empty_string(): void
    {
        $this->assertSame('', MigrateProductBatchJob::renderLayoutImagesBlock([]));
    }

    public function test_render_layout_images_block_escapes_html_in_url_and_alt(): void
    {
        $out = MigrateProductBatchJob::renderLayoutImagesBlock([
            ['id' => 1, 'url' => 'https://wp/x.jpg?a="><script>', 'alt' => 'a"><b>'],
        ]);

        $this->assertStringNotContainsString('<script>', $out);
        $this->assertStringNotContainsString('<b>', $out);
        $this->assertStringContainsString('&quot;', $out);
    }

    public function test_strip_layout_images_block_removes_existing_marker_block(): void
    {
        $desc = 'KEEP TEXT'.MigrateProductBatchJob::renderLayoutImagesBlock([
            ['id' => 5, 'url' => 'https://wp/old.jpg', 'alt' => ''],
        ]);

        $stripped = MigrateProductBatchJob::stripLayoutImagesBlock($desc);

        $this->assertSame('KEEP TEXT', $stripped);
        $this->assertStringNotContainsString('old.jpg', $stripped);
        $this->assertStringNotContainsString('sw:layout-images', $stripped);
    }

    public function test_strip_layout_images_block_noop_when_absent(): void
    {
        $this->assertSame('just a description', MigrateProductBatchJob::stripLayoutImagesBlock('just a description'));
    }
}

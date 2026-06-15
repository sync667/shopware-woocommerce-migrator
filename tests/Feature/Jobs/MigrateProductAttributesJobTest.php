<?php

namespace Tests\Feature\Jobs;

use App\Jobs\MigrateProductAttributesJob;
use App\Services\WooCommerceClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Mockery;
use PHPUnit\Framework\TestCase;

class MigrateProductAttributesJobTest extends TestCase
{
    public function test_implements_should_queue_on_migration_queue(): void
    {
        $this->assertTrue(in_array(ShouldQueue::class, class_implements(MigrateProductAttributesJob::class)));
        $job = new MigrateProductAttributesJob(1);
        $this->assertSame('migration', $job->queue);
    }

    public function test_has_long_timeout_so_it_can_register_hundreds_of_attributes(): void
    {
        $job = new MigrateProductAttributesJob(1);
        $this->assertGreaterThanOrEqual(600, $job->timeout);
    }

    public function test_truncated_slug_short_name_unchanged(): void
    {
        $this->assertSame('rozmiar', MigrateProductAttributesJob::truncatedSlug('Rozmiar'));
        $this->assertSame('kolor', MigrateProductAttributesJob::truncatedSlug('Kolor'));
    }

    public function test_truncated_slug_respects_28_char_limit(): void
    {
        // WC's woocommerce_rest_cannot_create error fires when slug > 28 chars.
        // Real failure from mig 63 — "Wskaźnik prędkości pracy narzędzia"
        $out = MigrateProductAttributesJob::truncatedSlug('Wskaźnik prędkości pracy narzędzia');
        $this->assertLessThanOrEqual(28, strlen($out));
    }

    public function test_truncated_slug_keeps_collisions_distinct(): void
    {
        // Two long names with the same 22-char prefix must still produce
        // different slugs because the crc32 suffix is name-specific.
        $a = 'Wymiary agregatu hydraulicznego';
        $b = 'Wymiary agregatu prądotwórczego';
        $this->assertNotSame(
            MigrateProductAttributesJob::truncatedSlug($a),
            MigrateProductAttributesJob::truncatedSlug($b)
        );
    }

    public function test_truncated_slug_handles_unslugifiable_input(): void
    {
        $out = MigrateProductAttributesJob::truncatedSlug('!@#$%');
        $this->assertNotSame('', $out);
        $this->assertLessThanOrEqual(28, strlen($out));
    }

    public function test_resolve_or_create_reuses_existing_when_only_slug_collides(): void
    {
        // Real mig 64 case: "Moc światła" (no dot) was registered in run 63, then run 64
        // sees "Moc światła." (with dot) — different name, same slug. Must reuse, not POST.
        $job = new class(1) extends MigrateProductAttributesJob
        {
            public function publicResolveOrCreate(WooCommerceClient $woo, string $name, array $byName, array $bySlug): ?int
            {
                return $this->resolveOrCreate($woo, $name, $byName, $bySlug);
            }
        };

        $woo = Mockery::mock(WooCommerceClient::class);
        $woo->shouldNotReceive('post');

        $existingBySlug = [MigrateProductAttributesJob::truncatedSlug('Moc światła') => 42];

        $id = $job->publicResolveOrCreate($woo, 'Moc światła.', [], $existingBySlug);

        $this->assertSame(42, $id);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}

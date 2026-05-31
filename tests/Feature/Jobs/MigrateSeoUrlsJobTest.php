<?php

namespace Tests\Feature\Jobs;

use App\Jobs\MigrateSeoUrlsJob;
use App\Models\MigrationEntity;
use App\Models\MigrationLog;
use App\Models\MigrationRun;
use App\Services\CancellationService;
use App\Services\StateManager;
use App\Shopware\Readers\SeoUrlReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MigrateSeoUrlsJobTest extends TestCase
{
    use RefreshDatabase;

    private MigrationRun $migration;

    private string $storageDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->migration = MigrationRun::create([
            'name' => 'SEO Test',
            'settings' => [
                'shopware' => [
                    'db_host' => '127.0.0.1',
                    'db_port' => 3306,
                    'db_database' => 'shopware',
                    'db_username' => 'root',
                    'db_password' => 'pass',
                    'language_id' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
                    'live_version_id' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
                    'base_url' => 'https://shop.test',
                ],
                'woocommerce' => [
                    'base_url' => 'https://woo.test',
                    'consumer_key' => 'ck_test',
                    'consumer_secret' => 'cs_test',
                ],
                'wordpress' => [
                    'username' => 'admin',
                    'app_password' => 'pass',
                ],
            ],
            'status' => 'running',
            'is_dry_run' => false,
        ]);

        $this->storageDir = storage_path("app/migrations/{$this->migration->id}");
        File::deleteDirectory($this->storageDir);

        Config::set('migration.redirection.enabled', true);
        Config::set('migration.redirection.group_name', 'Shopware Migration');
        Config::set('migration.redirection.default_code', 301);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storageDir);
        parent::tearDown();
    }

    private function bindReader(array $products = [], array $categories = [], array $cmsPages = []): void
    {
        $stub = new class($products, $categories, $cmsPages) extends SeoUrlReader
        {
            public function __construct(private array $products, private array $categories, private array $cmsPages)
            {
                // Skip parent constructor to avoid ShopwareDB dependency.
            }

            public function fetchAllForProducts(): array
            {
                return $this->products;
            }

            public function fetchAllForCategories(): array
            {
                return $this->categories;
            }

            public function fetchAllForCmsPages(): array
            {
                return $this->cmsPages;
            }
        };

        $this->app->bind(SeoUrlReader::class, fn () => $stub);
    }

    private function seoRow(string $id, string $foreignKey, string $route, string $path, bool $canonical = true): object
    {
        return (object) [
            'id' => $id,
            'foreign_key' => $foreignKey,
            'route_name' => $route,
            'path_info' => '/detail/'.$foreignKey,
            'seo_path_info' => $path,
            'is_canonical' => $canonical ? 1 : 0,
        ];
    }

    private function fakeAvailableRedirection(array $existingSources = []): void
    {
        Http::fake([
            'https://woo.test/wp-json/redirection/v1/group*' => Http::sequence()
                ->push(['items' => [['id' => 11, 'name' => 'Shopware Migration']], 'total' => 1], 200)
                ->whenEmpty(Http::response(['items' => [['id' => 11, 'name' => 'Shopware Migration']], 'total' => 1], 200)),
            'https://woo.test/wp-json/redirection/v1/redirect*' => function ($request) use ($existingSources) {
                if ($request->method() === 'POST') {
                    $payload = $request->data();

                    return Http::response([
                        'items' => [[
                            'id' => random_int(1000, 9999),
                            'url' => $payload['url'] ?? '',
                            'action_data' => ['url' => $payload['action_data']['url'] ?? ''],
                        ]],
                        'total' => 1,
                    ], 200);
                }

                return Http::response([
                    'items' => array_map(fn ($u) => ['url' => $u], $existingSources),
                    'total' => count($existingSources),
                ], 200);
            },
        ]);
    }

    private function runJob(): void
    {
        (new MigrateSeoUrlsJob($this->migration->id))->handle(
            app(StateManager::class),
            app(CancellationService::class),
        );
    }

    private function csvLines(): array
    {
        $path = $this->storageDir.'/redirects.csv';
        if (! file_exists($path)) {
            return [];
        }

        $contents = (string) file_get_contents($path);
        // Strip the UTF-8 BOM the job writes at the start of new files so individual line
        // assertions don't have to know about it.
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        }

        return array_values(array_filter(explode("\n", trim($contents))));
    }

    private function csvHasBom(): bool
    {
        $path = $this->storageDir.'/redirects.csv';

        return file_exists($path)
            && str_starts_with((string) file_get_contents($path), "\xEF\xBB\xBF");
    }

    public function test_happy_path_migrates_canonical_and_alias_for_product(): void
    {
        MigrationEntity::create([
            'migration_id' => $this->migration->id,
            'entity_type' => 'product',
            'shopware_id' => 'prodfk1',
            'woo_id' => 100,
            'status' => 'success',
            'payload' => ['slug' => 'prod-1'],
        ]);

        $this->bindReader(products: [
            $this->seoRow('seo1', 'prodfk1', 'frontend.detail.page', 'shop/prod-canonical', true),
            $this->seoRow('seo2', 'prodfk1', 'frontend.detail.page', 'shop/old-name-alias', false),
        ]);

        $this->fakeAvailableRedirection();

        $this->runJob();

        $rows = MigrationEntity::where('entity_type', 'seo_url')->get();
        $this->assertCount(2, $rows);
        $this->assertEquals(['success', 'success'], $rows->pluck('status')->all());
        $this->assertTrue($rows->every(fn ($r) => $r->woo_id !== null));

        Http::assertSent(fn ($r) => $r->method() === 'POST'
            && str_contains($r->url(), '/wp-json/redirection/v1/redirect')
            && $r['url'] === '/shop/prod-canonical'
            && $r['action_data']['url'] === '/product/prod-1/');

        Http::assertSent(fn ($r) => $r->method() === 'POST'
            && str_contains($r->url(), '/wp-json/redirection/v1/redirect')
            && $r['url'] === '/shop/old-name-alias');

        $lines = $this->csvLines();
        $this->assertSame('source,target,regex,code', $lines[0]);
        $this->assertCount(3, $lines);
    }

    public function test_skip_when_source_already_exists_in_redirection(): void
    {
        MigrationEntity::create([
            'migration_id' => $this->migration->id,
            'entity_type' => 'product',
            'shopware_id' => 'pfk',
            'woo_id' => 100,
            'status' => 'success',
            'payload' => ['slug' => 'p1'],
        ]);

        $this->bindReader(products: [
            $this->seoRow('seo1', 'pfk', 'frontend.detail.page', 'already-there'),
        ]);

        $this->fakeAvailableRedirection(['/already-there']);

        $this->runJob();

        $entity = MigrationEntity::where('entity_type', 'seo_url')->first();
        $this->assertSame('skipped', $entity->status);
        $this->assertSame('exists_in_redirection', $entity->payload['skip_reason']);

        Http::assertNotSent(fn ($r) => $r->method() === 'POST'
            && str_contains($r->url(), '/wp-json/redirection/v1/redirect'));
    }

    public function test_idempotency_second_run_makes_no_posts(): void
    {
        MigrationEntity::create([
            'migration_id' => $this->migration->id,
            'entity_type' => 'product',
            'shopware_id' => 'pfk',
            'woo_id' => 100,
            'status' => 'success',
            'payload' => ['slug' => 'p1'],
        ]);

        $this->bindReader(products: [
            $this->seoRow('seo1', 'pfk', 'frontend.detail.page', 'first-time'),
        ]);

        $this->fakeAvailableRedirection();

        $this->runJob();

        Http::fake([
            'https://woo.test/wp-json/redirection/v1/group*' => Http::response(['items' => [['id' => 11, 'name' => 'Shopware Migration']], 'total' => 1], 200),
            'https://woo.test/wp-json/redirection/v1/redirect*' => Http::response(['items' => [], 'total' => 0], 200),
        ]);

        $this->runJob();

        Http::assertNotSent(fn ($r) => $r->method() === 'POST'
            && str_contains($r->url(), '/wp-json/redirection/v1/redirect'));
    }

    public function test_entity_not_yet_migrated_is_left_unmigrated(): void
    {
        $this->bindReader(products: [
            $this->seoRow('seo1', 'missing', 'frontend.detail.page', 'orphan'),
        ]);

        $this->fakeAvailableRedirection();

        $this->runJob();

        $this->assertSame(0, MigrationEntity::where('entity_type', 'seo_url')->count());
        Http::assertNotSent(fn ($r) => $r->method() === 'POST'
            && str_contains($r->url(), '/wp-json/redirection/v1/redirect'));

        $warning = MigrationLog::where('migration_id', $this->migration->id)
            ->where('level', 'warning')->first();
        $this->assertNotNull($warning);
        $this->assertStringContainsString('not yet migrated', $warning->message);
    }

    public function test_slug_missing_uses_id_fallback(): void
    {
        MigrationEntity::create([
            'migration_id' => $this->migration->id,
            'entity_type' => 'product',
            'shopware_id' => 'pfk',
            'woo_id' => 555,
            'status' => 'success',
            'payload' => ['slug' => null],
        ]);

        $this->bindReader(products: [
            $this->seoRow('seo1', 'pfk', 'frontend.detail.page', 'no-slug-product'),
        ]);

        $this->fakeAvailableRedirection();

        $this->runJob();

        Http::assertSent(fn ($r) => $r->method() === 'POST'
            && str_contains($r->url(), '/wp-json/redirection/v1/redirect')
            && $r['action_data']['url'] === '/?p=555');
    }

    public function test_plugin_unavailable_runs_file_only(): void
    {
        MigrationEntity::create([
            'migration_id' => $this->migration->id,
            'entity_type' => 'product',
            'shopware_id' => 'pfk',
            'woo_id' => 1,
            'status' => 'success',
            'payload' => ['slug' => 'p'],
        ]);

        $this->bindReader(products: [
            $this->seoRow('seo1', 'pfk', 'frontend.detail.page', 'something'),
        ]);

        Http::fake([
            'https://woo.test/wp-json/redirection/v1/*' => Http::response('Not found', 404),
        ]);

        $this->runJob();

        $entity = MigrationEntity::where('entity_type', 'seo_url')->first();
        $this->assertSame('skipped', $entity->status);
        $this->assertSame('plugin_unavailable', $entity->payload['skip_reason']);

        Http::assertNotSent(fn ($r) => $r->method() === 'POST'
            && str_contains($r->url(), '/wp-json/redirection/v1/redirect'));

        $this->assertGreaterThan(1, count($this->csvLines()));
    }

    public function test_api_disabled_skips_all_http(): void
    {
        Config::set('migration.redirection.enabled', false);

        MigrationEntity::create([
            'migration_id' => $this->migration->id,
            'entity_type' => 'product',
            'shopware_id' => 'pfk',
            'woo_id' => 1,
            'status' => 'success',
            'payload' => ['slug' => 'p'],
        ]);

        $this->bindReader(products: [
            $this->seoRow('seo1', 'pfk', 'frontend.detail.page', 'thing'),
        ]);

        Http::fake();

        $this->runJob();

        $entity = MigrationEntity::where('entity_type', 'seo_url')->first();
        $this->assertSame('skipped', $entity->status);
        $this->assertSame('api_disabled', $entity->payload['skip_reason']);

        Http::assertNothingSent();
        $this->assertGreaterThan(1, count($this->csvLines()));
    }

    public function test_dry_run_skips_creates_but_writes_csv(): void
    {
        $this->migration->update(['is_dry_run' => true]);

        MigrationEntity::create([
            'migration_id' => $this->migration->id,
            'entity_type' => 'product',
            'shopware_id' => 'pfk',
            'woo_id' => 1,
            'status' => 'success',
            'payload' => ['slug' => 'p'],
        ]);

        $this->bindReader(products: [
            $this->seoRow('seo1', 'pfk', 'frontend.detail.page', 'dryrun-thing'),
        ]);

        $this->fakeAvailableRedirection();

        $this->runJob();

        $entity = MigrationEntity::where('entity_type', 'seo_url')->first();
        $this->assertSame('skipped', $entity->status);
        $this->assertSame('dry_run', $entity->payload['skip_reason']);

        Http::assertNotSent(fn ($r) => $r->method() === 'POST'
            && str_contains($r->url(), '/wp-json/redirection/v1/redirect'));

        $lines = $this->csvLines();
        $this->assertSame('/dryrun-thing,/product/p/,,301', $lines[1]);
    }

    public function test_self_redirect_is_skipped(): void
    {
        MigrationEntity::create([
            'migration_id' => $this->migration->id,
            'entity_type' => 'product',
            'shopware_id' => 'pfk',
            'woo_id' => 1,
            'status' => 'success',
            'payload' => ['slug' => 'same'],
        ]);

        $this->bindReader(products: [
            $this->seoRow('seo1', 'pfk', 'frontend.detail.page', 'product/same'),
        ]);

        $this->fakeAvailableRedirection();

        $this->runJob();

        $entity = MigrationEntity::where('entity_type', 'seo_url')->first();
        $this->assertSame('skipped', $entity->status);
        $this->assertSame('self_redirect', $entity->payload['skip_reason']);

        Http::assertNotSent(fn ($r) => $r->method() === 'POST'
            && str_contains($r->url(), '/wp-json/redirection/v1/redirect'));

        // CSV should not contain self-redirects.
        $this->assertCount(1, $this->csvLines());
    }

    public function test_source_collision_first_wins(): void
    {
        MigrationEntity::create([
            'migration_id' => $this->migration->id,
            'entity_type' => 'product',
            'shopware_id' => 'p1',
            'woo_id' => 1,
            'status' => 'success',
            'payload' => ['slug' => 'one'],
        ]);
        MigrationEntity::create([
            'migration_id' => $this->migration->id,
            'entity_type' => 'product',
            'shopware_id' => 'p2',
            'woo_id' => 2,
            'status' => 'success',
            'payload' => ['slug' => 'two'],
        ]);

        $this->bindReader(products: [
            $this->seoRow('seo1', 'p1', 'frontend.detail.page', 'shared-path'),
            $this->seoRow('seo2', 'p2', 'frontend.detail.page', 'shared-path'),
        ]);

        $this->fakeAvailableRedirection();

        $this->runJob();

        $rows = MigrationEntity::where('entity_type', 'seo_url')->orderBy('id')->get();
        $this->assertSame('success', $rows[0]->status);
        $this->assertSame('skipped', $rows[1]->status);
        $this->assertSame('source_collision', $rows[1]->payload['skip_reason']);
    }

    public function test_cancellation_stops_mid_loop(): void
    {
        MigrationEntity::create([
            'migration_id' => $this->migration->id,
            'entity_type' => 'product',
            'shopware_id' => 'pfk',
            'woo_id' => 1,
            'status' => 'success',
            'payload' => ['slug' => 'p'],
        ]);

        $this->bindReader(products: [
            $this->seoRow('seo1', 'pfk', 'frontend.detail.page', 'thing'),
        ]);

        $this->fakeAvailableRedirection();

        $cancellation = new class extends CancellationService
        {
            public function isCancelled(int $migrationId): bool
            {
                return true;
            }
        };
        $this->app->instance(CancellationService::class, $cancellation);

        $this->runJob();

        $this->assertSame(0, MigrationEntity::where('entity_type', 'seo_url')->count());
        Http::assertNotSent(fn ($r) => $r->method() === 'POST'
            && str_contains($r->url(), '/wp-json/redirection/v1/redirect'));
    }

    public function test_csv_format_matches_expected_layout(): void
    {
        MigrationEntity::create([
            'migration_id' => $this->migration->id,
            'entity_type' => 'product',
            'shopware_id' => 'p',
            'woo_id' => 1,
            'status' => 'success',
            'payload' => ['slug' => 'shoe'],
        ]);
        MigrationEntity::create([
            'migration_id' => $this->migration->id,
            'entity_type' => 'category',
            'shopware_id' => 'c',
            'woo_id' => 2,
            'status' => 'success',
            'payload' => ['slug' => 'cat'],
        ]);

        $this->bindReader(
            products: [$this->seoRow('s1', 'p', 'frontend.detail.page', 'old-shoe')],
            categories: [$this->seoRow('s2', 'c', 'frontend.navigation.page', 'old-cat')],
        );

        $this->fakeAvailableRedirection();

        $this->runJob();

        $lines = $this->csvLines();
        $this->assertSame('source,target,regex,code', $lines[0]);
        $this->assertSame('/old-shoe,/product/shoe/,,301', $lines[1]);
        $this->assertSame('/old-cat,/product-category/cat/,,301', $lines[2]);
    }

    public function test_pending_entity_with_slug_is_not_redirected_yet(): void
    {
        // A pending entity may have a placeholder slug from an earlier pass but
        // no actual woo_id yet. Creating a redirect now would point at a URL that
        // 404s on the WordPress side, so we must leave the seo_url row for the
        // next migration pass instead.
        MigrationEntity::create([
            'migration_id' => $this->migration->id,
            'entity_type' => 'cms_page',
            'shopware_id' => 'cms1',
            'woo_id' => null,
            'status' => 'pending',
            'payload' => ['slug' => 'shipping'],
        ]);

        $this->bindReader(cmsPages: [
            $this->seoRow('s1', 'cms1', 'frontend.cms.page', 'help/shipping'),
        ]);

        $this->fakeAvailableRedirection();

        $this->runJob();

        Http::assertNotSent(fn ($r) => $r->method() === 'POST'
            && str_contains($r->url(), '/wp-json/redirection/v1/redirect'));

        // State row should be untouched so the next pass retries it once the
        // CMS page has actually been created.
        $seoEntity = MigrationEntity::where('migration_id', $this->migration->id)
            ->where('entity_type', 'seo_url')
            ->where('shopware_id', 's1')
            ->first();
        $this->assertNull($seoEntity);

        $this->assertTrue(
            MigrationLog::where('migration_id', $this->migration->id)
                ->where('shopware_id', 's1')
                ->where('level', 'warning')
                ->exists()
        );
    }

    public function test_csv_is_written_with_utf8_bom(): void
    {
        $this->bindReader(products: [
            $this->seoRow('s1', 'p1', 'frontend.detail.page', 'shoe'),
        ]);
        MigrationEntity::create([
            'migration_id' => $this->migration->id,
            'entity_type' => 'product',
            'shopware_id' => 'p1',
            'woo_id' => 11,
            'status' => 'success',
            'payload' => ['slug' => 'shoe'],
        ]);
        Config::set('migration.redirection.enabled', false);

        $this->runJob();

        $this->assertTrue($this->csvHasBom(), 'CSV should start with a UTF-8 BOM so importers detect encoding correctly.');
    }
}

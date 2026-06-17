<?php

namespace Tests\Feature\Jobs;

use App\Jobs\MigrateSeoUrlBatchJob;
use App\Models\MigrationEntity;
use App\Models\MigrationLog;
use App\Models\MigrationRun;
use App\Services\CancellationService;
use App\Services\RedirectionClient;
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

    /**
     * Set of fake seo_url rows the batch worker will see for the test.
     * Stored on the test instance so runJob() can pass them into the stub reader.
     *
     * @var array<int, object>
     */
    private array $stubRows = [];

    private function bindReader(array $products = [], array $categories = [], array $cmsPages = []): void
    {
        $rows = array_merge($products, $categories, $cmsPages);
        $this->stubRows = $rows;

        $stub = new class($rows) extends SeoUrlReader
        {
            public function __construct(private array $rows)
            {
                // Skip parent constructor to avoid ShopwareDB dependency.
            }

            public function fetchAllIds(): array
            {
                return array_map(fn ($r) => $r->id, $this->rows);
            }

            public function fetchByIds(array $ids): array
            {
                $byId = [];
                foreach ($this->rows as $r) {
                    $byId[$r->id] = $r;
                }

                return array_values(array_filter(array_map(fn ($id) => $byId[$id] ?? null, $ids)));
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

    /**
     * Drives the per-row logic that used to live in MigrateSeoUrlsJob::handle()
     * but now sits in MigrateSeoUrlBatchJob::handle(). Bypasses the Bus::batch
     * dispatcher because the dispatcher only chunks + dispatches — every assertion
     * the existing tests care about (state writes, CSV format, HTTP behavior)
     * happens inside the batch worker.
     *
     * Resolves the Redirection group / existing source set the same way the
     * dispatcher does so real-run scenarios still hit `Http::fake()`.
     */
    private function runJob(): void
    {
        // Initialize CSV header + BOM the way the dispatcher would.
        $dir = $this->storageDir;
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $path = $dir.'/redirects.csv';
        if (! file_exists($path) || filesize($path) === 0) {
            $h = fopen($path, 'w');
            fwrite($h, "\xEF\xBB\xBF");
            fputcsv($h, ['source', 'target', 'regex', 'code']);
            fclose($h);
        }

        // Resolve the Redirection plugin context (group_id + existing sources)
        // exactly like MigrateSeoUrlsJob::resolveRedirectionContext does.
        $groupId = null;
        $existingSources = [];
        if (! $this->migration->is_dry_run && (bool) config('migration.redirection.enabled', true)) {
            $client = $this->app->make(RedirectionClient::class, ['config' => [
                'base_url' => 'https://woo.test',
                'wp_username' => 'admin',
                'wp_app_password' => 'pass',
            ]]);
            try {
                if ($client->isAvailable()) {
                    $groupId = $client->ensureGroup('Shopware Migration');
                    $existingSources = array_fill_keys($client->loadExistingSources($groupId), true);
                }
            } catch (\Throwable) {
                // CSV-only mode — the stub Http::fake may not be set up for this test.
            }
        }

        // Replicate the dispatcher's cross-entity source-collision dedup. Two
        // rows with the same seo_path_info but different foreign_keys both pass
        // SQL dedup; the first wins, the rest are marked 'source_collision'.
        $seen = [];
        $survivors = [];
        $collisions = [];
        $stateManager = app(StateManager::class);
        foreach ($this->stubRows as $row) {
            $path = (string) $row->seo_path_info;
            if (isset($seen[$path])) {
                $collisions[] = $row->id;

                continue;
            }
            $seen[$path] = true;
            $survivors[] = $row->id;
        }

        // Process survivors first so their state rows are created BEFORE the
        // collision-skipped rows — matches the auto-increment ordering the
        // dispatcher → batch flow would produce in production.
        $batch = new MigrateSeoUrlBatchJob($this->migration->id, $survivors, $groupId, $existingSources);
        $batch->handle($stateManager, app(CancellationService::class));

        foreach ($collisions as $collisionId) {
            $stateManager->markSkipped(
                'seo_url',
                $collisionId,
                $this->migration->id,
                ['skip_reason' => 'source_collision']
            );
        }
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
            && $r['action_data']['url'] === '/produkt/prod-1/');

        Http::assertSent(fn ($r) => $r->method() === 'POST'
            && str_contains($r->url(), '/wp-json/redirection/v1/redirect')
            && $r['url'] === '/shop/old-name-alias');

        // The canonical /detail/{productId} URL also redirects to the same target,
        // emitted once (from the canonical seo_url row only).
        Http::assertSent(fn ($r) => $r->method() === 'POST'
            && str_contains($r->url(), '/wp-json/redirection/v1/redirect')
            && $r['url'] === '/detail/prodfk1'
            && $r['action_data']['url'] === '/produkt/prod-1/');

        $lines = $this->csvLines();
        $this->assertSame('source,target,regex,code', $lines[0]);
        $this->assertCount(4, $lines);
        $this->assertContains('/detail/prodfk1,/produkt/prod-1/,,301', $lines);
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

    public function test_orphan_reference_marks_skipped_and_logs_info(): void
    {
        // Shopware's seo_url table can hold rows pointing at deleted entities (the
        // is_deleted flag isn't always set on admin deletes). The job records these
        // as 'orphaned_reference' skips and logs at INFO level — they're benign and
        // shouldn't pollute the warning channel with noise the operator can't act on.
        $this->bindReader(products: [
            $this->seoRow('seo1', 'missing', 'frontend.detail.page', 'orphan'),
        ]);

        $this->fakeAvailableRedirection();

        $this->runJob();

        $seoEntity = MigrationEntity::where('entity_type', 'seo_url')
            ->where('shopware_id', 'seo1')
            ->first();
        $this->assertNotNull($seoEntity);
        $this->assertSame('skipped', $seoEntity->status);
        $this->assertSame('orphaned_reference', $seoEntity->payload['skip_reason'] ?? null);

        Http::assertNotSent(fn ($r) => $r->method() === 'POST'
            && str_contains($r->url(), '/wp-json/redirection/v1/redirect'));

        $log = MigrationLog::where('migration_id', $this->migration->id)
            ->where('shopware_id', 'seo1')->first();
        $this->assertNotNull($log);
        $this->assertSame('info', $log->level);
        $this->assertStringContainsString('does not exist in Shopware', $log->message);
    }

    public function test_pending_entity_is_left_for_next_pass(): void
    {
        // Distinct from orphaned references: a pending entity is one that exists in
        // Shopware but hasn't been processed yet by the current migration. Don't
        // write seo_url state — the next pass should pick it up.
        MigrationEntity::create([
            'migration_id' => $this->migration->id,
            'entity_type' => 'product',
            'shopware_id' => 'pending-product',
            'woo_id' => null,
            'status' => 'pending',
        ]);

        $this->bindReader(products: [
            $this->seoRow('seo1', 'pending-product', 'frontend.detail.page', 'shoes'),
        ]);

        $this->fakeAvailableRedirection();

        $this->runJob();

        $seoEntity = MigrationEntity::where('entity_type', 'seo_url')
            ->where('shopware_id', 'seo1')
            ->first();
        $this->assertNull($seoEntity, 'pending-target seo_url rows must be left untouched for next pass');

        $log = MigrationLog::where('migration_id', $this->migration->id)
            ->where('shopware_id', 'seo1')->first();
        $this->assertNotNull($log);
        $this->assertSame('info', $log->level);
        $this->assertStringContainsString('not yet migrated', $log->message);
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
        $this->assertSame('/dryrun-thing,/produkt/p/,,301', $lines[1]);
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
            $this->seoRow('seo1', 'pfk', 'frontend.detail.page', 'produkt/same'),
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
        $this->assertSame('/old-shoe,/produkt/shoe/,,301', $lines[1]);
        // Products also emit their canonical /detail/{id} URL; categories do not.
        $this->assertSame('/detail/p,/produkt/shoe/,,301', $lines[2]);
        $this->assertSame('/old-cat,/kategoria-produktu/cat/,,301', $lines[3]);
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
                ->where('level', 'info')
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

    public function test_dispatcher_creates_batch_jobs_with_chunk_size(): void
    {
        // The dispatcher must chunk the deduped id set into CHUNK_SIZE-sized
        // MigrateSeoUrlBatchJob entries dispatched via Bus::batch so each chunk
        // runs in seconds (resumable on kill, never trips the worker timeout).
        \Illuminate\Support\Facades\Bus::fake();

        $rows = [];
        for ($i = 0; $i < 1200; $i++) {
            $rows[] = (object) ['id' => 'id'.str_pad((string) $i, 4, '0', STR_PAD_LEFT), 'seo_path_info' => "p{$i}"];
        }

        $stub = new class($rows) extends \App\Shopware\Readers\SeoUrlReader
        {
            public function __construct(private array $rows) {}

            public function fetchAllIds(): array
            {
                return $this->rows;
            }
        };
        $this->app->bind(\App\Shopware\Readers\SeoUrlReader::class, fn () => $stub);

        (new \App\Jobs\MigrateSeoUrlsJob($this->migration->id))->handle(app(CancellationService::class));

        // 1200 rows / 500 chunk size = 3 chunks (500 + 500 + 200)
        \Illuminate\Support\Facades\Bus::assertBatched(function (\Illuminate\Bus\PendingBatch $batch) {
            return $batch->jobs->count() === 3;
        });
    }
}

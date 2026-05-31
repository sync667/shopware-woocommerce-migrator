<?php

namespace Tests\Feature\Jobs;

use App\Jobs\MigrateCustomerWishlistsJob;
use App\Models\MigrationLog;
use App\Models\MigrationRun;
use App\Services\CancellationService;
use App\Shopware\Readers\CustomerWishlistReader;
use Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class MigrateCustomerWishlistsJobTest extends TestCase
{
    use RefreshDatabase;

    private MigrationRun $migration;

    private string $storageDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->migration = MigrationRun::create([
            'name' => 'Wishlist Test',
            'settings' => ['shopware' => ['base_url' => 'https://shop.test']],
            'status' => 'running',
            'is_dry_run' => false,
        ]);

        $this->storageDir = storage_path("app/migrations/{$this->migration->id}");
        File::deleteDirectory($this->storageDir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storageDir);
        parent::tearDown();
    }

    private function csvLines(): array
    {
        $path = $this->storageDir.'/wishlists.csv';
        if (! file_exists($path)) {
            return [];
        }

        $contents = (string) file_get_contents($path);
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        }

        return array_values(array_filter(explode("\n", trim($contents))));
    }

    private function runJob(array $rows): void
    {
        $job = new class($this->migration->id, $rows) extends MigrateCustomerWishlistsJob
        {
            public function __construct(int $migrationId, private array $rows)
            {
                parent::__construct($migrationId);
            }

            protected function makeReader(MigrationRun $migration): CustomerWishlistReader
            {
                return new class($this->rows) extends CustomerWishlistReader
                {
                    public function __construct(private array $rows)
                    {
                        // Skip parent so no ShopwareDB connection.
                    }

                    public function stream(): Generator
                    {
                        foreach ($this->rows as $row) {
                            yield $row;
                        }
                    }
                };
            }
        };

        $job->handle(app(CancellationService::class));
    }

    public function test_exports_wishlist_rows_to_csv(): void
    {
        $this->runJob([
            (object) [
                'wishlist_product_id' => 'wp1',
                'wishlist_id' => 'wl1',
                'customer_id' => 'cust1',
                'customer_email' => 'jan@example.pl',
                'product_id' => 'prod1',
                'sku' => 'SKU-001',
                'added_at' => '2025-02-01 12:00:00',
            ],
            (object) [
                'wishlist_product_id' => 'wp2',
                'wishlist_id' => 'wl1',
                'customer_id' => 'cust1',
                'customer_email' => 'jan@example.pl',
                'product_id' => 'prod2',
                'sku' => 'SKU-002',
                'added_at' => '2025-02-02 12:00:00',
            ],
        ]);

        $lines = $this->csvLines();
        $this->assertSame(
            'wishlist_product_id,wishlist_id,customer_id,customer_email,product_id,sku,added_at',
            $lines[0]
        );
        $this->assertCount(3, $lines);
        $this->assertStringContainsString('SKU-001', $lines[1]);
        $this->assertStringContainsString('SKU-002', $lines[2]);

        $log = MigrationLog::where('migration_id', $this->migration->id)->latest('id')->first();
        $this->assertStringContainsString('Exported 2 wishlist', (string) $log?->message);
    }
}

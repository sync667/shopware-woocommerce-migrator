<?php

namespace Tests\Feature\Jobs;

use App\Jobs\MigrateNewsletterRecipientsJob;
use App\Models\MigrationLog;
use App\Models\MigrationRun;
use App\Services\CancellationService;
use App\Shopware\Readers\NewsletterRecipientReader;
use Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class MigrateNewsletterRecipientsJobTest extends TestCase
{
    use RefreshDatabase;

    private MigrationRun $migration;

    private string $storageDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->migration = MigrationRun::create([
            'name' => 'NL Test',
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
        $path = $this->storageDir.'/newsletter_recipients.csv';
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
        $job = new class($this->migration->id, $rows) extends MigrateNewsletterRecipientsJob
        {
            public function __construct(int $migrationId, private array $rows)
            {
                parent::__construct($migrationId);
            }

            protected function makeReader(MigrationRun $migration): NewsletterRecipientReader
            {
                return new class($this->rows) extends NewsletterRecipientReader
                {
                    public function __construct(private array $rows)
                    {
                        // Skip parent constructor so no ShopwareDB connection is attempted.
                    }

                    public function stream(?\DateTimeInterface $since = null): Generator
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

    public function test_exports_subscribers_with_utf8_bom_and_header(): void
    {
        $this->runJob([
            (object) [
                'id' => 'nl1',
                'email' => 'jan@example.pl',
                'title' => null,
                'first_name' => 'Jan',
                'last_name' => 'Kowalski',
                'zip_code' => '00-001',
                'city' => 'Warszawa',
                'street' => 'ul. Marszałkowska 1',
                'status' => 'optIn',
                'hash' => 'abc123',
                'language_id' => 'lang-pl',
                'sales_channel_id' => 'chan-1',
                'confirmed_at' => '2025-01-15 09:00:00',
                'created_at' => '2025-01-15 09:00:00',
                'updated_at' => '2025-01-15 09:00:00',
            ],
        ]);

        $lines = $this->csvLines();
        $this->assertSame(
            'shopware_id,email,title,first_name,last_name,zip_code,city,street,status,hash,language_id,sales_channel_id,confirmed_at,created_at,updated_at',
            $lines[0]
        );
        $this->assertStringContainsString('jan@example.pl', $lines[1] ?? '');
        $this->assertStringContainsString('Jan', $lines[1] ?? '');

        $bytes = (string) file_get_contents($this->storageDir.'/newsletter_recipients.csv');
        $this->assertTrue(str_starts_with($bytes, "\xEF\xBB\xBF"));

        $log = MigrationLog::where('migration_id', $this->migration->id)->latest('id')->first();
        $this->assertStringContainsString('Exported 1 newsletter recipient', (string) $log?->message);
    }

    public function test_writes_zero_subscribers_log_when_empty(): void
    {
        $this->runJob([]);

        $lines = $this->csvLines();
        $this->assertCount(1, $lines, 'header row only');

        $log = MigrationLog::where('migration_id', $this->migration->id)->latest('id')->first();
        $this->assertStringContainsString('Exported 0', (string) $log?->message);
    }
}

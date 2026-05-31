<?php

namespace App\Jobs;

use App\Models\MigrationLog;
use App\Models\MigrationRun;
use App\Services\CancellationService;
use App\Services\ShopwareDB;
use App\Shopware\Readers\NewsletterRecipientReader;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Optional newsletter-recipient export. WooCommerce has no native newsletter store, so
 * this job emits a single CSV at storage/app/migrations/{id}/newsletter_recipients.csv
 * that the operator can import into MailPoet / Mailchimp / Klaviyo / whatever target
 * mail platform the new shop uses. Only opted-in migrations get this job dispatched
 * (see `newsletter_options.enabled` in MigrationRun settings).
 */
class MigrateNewsletterRecipientsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 5;

    public int $timeout = 1800;

    public function __construct(protected int $migrationId) {}

    public function handle(CancellationService $cancellation): void
    {
        $migration = MigrationRun::findOrFail($this->migrationId);

        if ($cancellation->isCancelled($this->migrationId)) {
            return;
        }

        $reader = $this->makeReader($migration);

        $since = $migration->sync_mode === 'delta' && $migration->last_sync_at
            ? $migration->last_sync_at
            : null;

        $handle = $this->openCsv();
        $count = 0;

        try {
            foreach ($reader->stream($since) as $row) {
                if ($cancellation->isCancelled($this->migrationId)) {
                    return;
                }

                fputcsv($handle, [
                    $row->id ?? '',
                    $row->email ?? '',
                    $row->title ?? '',
                    $row->first_name ?? '',
                    $row->last_name ?? '',
                    $row->zip_code ?? '',
                    $row->city ?? '',
                    $row->street ?? '',
                    $row->status ?? '',
                    $row->hash ?? '',
                    $row->language_id ?? '',
                    $row->sales_channel_id ?? '',
                    $row->confirmed_at ?? '',
                    $row->created_at ?? '',
                    $row->updated_at ?? '',
                ]);
                $count++;
            }
        } finally {
            fclose($handle);
        }

        MigrationLog::create([
            'migration_id' => $this->migrationId,
            'entity_type' => 'newsletter_recipient',
            'level' => 'info',
            'message' => "Exported {$count} newsletter recipient(s) to CSV (import into your mailing platform of choice).",
            'created_at' => now(),
        ]);
    }

    protected function makeReader(MigrationRun $migration): NewsletterRecipientReader
    {
        $db = ShopwareDB::fromMigration($migration);

        return app(NewsletterRecipientReader::class, ['db' => $db]);
    }

    /**
     * @return resource
     */
    protected function openCsv()
    {
        $dir = storage_path("app/migrations/{$this->migrationId}");
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $path = $dir.'/newsletter_recipients.csv';

        $isNew = ! file_exists($path) || filesize($path) === 0;
        $handle = fopen($path, 'a');
        if ($handle === false) {
            throw new \RuntimeException("Unable to open CSV at {$path}");
        }

        if ($isNew) {
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, [
                'shopware_id',
                'email',
                'title',
                'first_name',
                'last_name',
                'zip_code',
                'city',
                'street',
                'status',
                'hash',
                'language_id',
                'sales_channel_id',
                'confirmed_at',
                'created_at',
                'updated_at',
            ]);
        }

        return $handle;
    }
}

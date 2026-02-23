<?php

namespace App\Console\Commands;

use App\Models\MigrationRun;
use App\Services\ShopwareDB;
use App\Shopware\Readers\CmsPageReader;
use Illuminate\Console\Command;

class ListCmsPagesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shopware:list-cms-pages {--migration-id=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List all available CMS pages from Shopware';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $migrationId = $this->option('migration-id');

        if (! $migrationId) {
            $this->error('Please provide a migration ID using --migration-id');

            return self::FAILURE;
        }

        try {
            $migration = MigrationRun::findOrFail($migrationId);
        } catch (\Exception $e) {
            $this->error("Migration run with ID {$migrationId} not found.");

            return self::FAILURE;
        }

        $this->info('Fetching CMS pages from Shopware...');

        $db = ShopwareDB::fromMigration($migration);
        $reader = new CmsPageReader($db);

        $pages = $reader->fetchAll();

        if (empty($pages)) {
            $this->warn('No CMS pages found in Shopware.');

            return self::SUCCESS;
        }

        $this->info('Found '.count($pages)." CMS page(s):\n");

        $tableData = [];
        foreach ($pages as $page) {
            $tableData[] = [
                'ID' => $page->id,
                'Type' => $page->type,
                'Name' => $page->name ?: '(Untitled)',
                'Locked' => $page->locked ? 'Yes' : 'No',
            ];
        }

        $this->table(
            ['ID', 'Type', 'Name', 'Locked'],
            $tableData
        );

        $this->newLine();
        $this->info('Migration Options:');
        $this->line('  • Migrate all pages:');
        $this->line("    php artisan shopware:migrate --migration-id={$migrationId} --cms-all");
        $this->newLine();
        $this->line('  • Migrate specific pages:');
        $exampleIds = array_slice(array_column($tableData, 'ID'), 0, 2);
        $exampleIdsStr = implode(',', $exampleIds);
        $this->line("    php artisan shopware:migrate --migration-id={$migrationId} --cms-ids={$exampleIdsStr}");
        $this->newLine();

        return self::SUCCESS;
    }
}

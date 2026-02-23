<?php

namespace App\Console\Commands;

use App\Models\MigrationRun;
use App\Services\ContentMigrator;
use App\Services\ImageMigrator;
use App\Services\ShopwareDB;
use App\Shopware\Readers\ProductReader;
use Illuminate\Console\Command;

class AnalyzeProductContentCommand extends Command
{
    protected $signature = 'shopware:analyze-content {--migration-id=}';

    protected $description = 'Analyze product descriptions to show content types and test migration';

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

        $this->info('Analyzing product content from Shopware...');
        $this->newLine();

        $db = ShopwareDB::fromMigration($migration);
        $reader = new ProductReader($db);

        // Mock ImageMigrator for testing
        $imageMigrator = $this->createMockImageMigrator();
        $contentMigrator = new ContentMigrator($imageMigrator);

        $products = $reader->fetchAllParents();

        $stats = [
            'total' => count($products),
            'with_html' => 0,
            'with_images' => 0,
            'with_tables' => 0,
            'with_lists' => 0,
            'with_videos' => 0,
            'empty' => 0,
        ];

        $examples = [];

        foreach ($products as $product) {
            $description = $product->description ?? '';

            if (empty($description)) {
                $stats['empty']++;

                continue;
            }

            if (strip_tags($description) !== $description) {
                $stats['with_html']++;
            }

            if (preg_match('/<img/i', $description)) {
                $stats['with_images']++;
                if (count($examples) < 3) {
                    $examples[] = [
                        'type' => 'Image',
                        'sku' => $product->sku,
                        'name' => $product->name,
                        'sample' => substr($description, 0, 200),
                    ];
                }
            }

            if (preg_match('/<table/i', $description)) {
                $stats['with_tables']++;
            }

            if (preg_match('/<(ul|ol)/i', $description)) {
                $stats['with_lists']++;
            }

            if (preg_match('/<iframe/i', $description)) {
                $stats['with_videos']++;
            }
        }

        // Display statistics
        $this->info('📊 Content Analysis Results:');
        $this->newLine();

        $this->table(
            ['Metric', 'Count', 'Percentage'],
            [
                ['Total Products', $stats['total'], '100%'],
                ['With HTML Content', $stats['with_html'], $this->percentage($stats['with_html'], $stats['total'])],
                ['With Images', $stats['with_images'], $this->percentage($stats['with_images'], $stats['total'])],
                ['With Tables', $stats['with_tables'], $this->percentage($stats['with_tables'], $stats['total'])],
                ['With Lists', $stats['with_lists'], $this->percentage($stats['with_lists'], $stats['total'])],
                ['With Videos/Embeds', $stats['with_videos'], $this->percentage($stats['with_videos'], $stats['total'])],
                ['Empty Descriptions', $stats['empty'], $this->percentage($stats['empty'], $stats['total'])],
            ]
        );

        // Show examples
        if (! empty($examples)) {
            $this->newLine();
            $this->info('📄 Sample Products with Rich Content:');
            $this->newLine();

            foreach ($examples as $example) {
                $this->line("<fg=cyan>Type:</> {$example['type']}");
                $this->line("<fg=cyan>SKU:</> {$example['sku']}");
                $this->line("<fg=cyan>Name:</> {$example['name']}");
                $this->line('<fg=yellow>Sample:</> '.substr($example['sample'], 0, 150).'...');
                $this->newLine();
            }
        }

        // Test ContentMigrator with sample
        if ($stats['with_html'] > 0) {
            $this->info('🧪 Testing ContentMigrator...');
            $this->newLine();

            $sampleProduct = null;
            foreach ($products as $product) {
                if (! empty($product->description) && strip_tags($product->description) !== $product->description) {
                    $sampleProduct = $product;
                    break;
                }
            }

            if ($sampleProduct) {
                $this->line("<fg=cyan>Testing with product:</> {$sampleProduct->name}");
                $this->newLine();

                $originalLength = strlen($sampleProduct->description);
                $processed = $contentMigrator->processHtmlContent($sampleProduct->description);
                $processedLength = strlen($processed);

                $this->line("<fg=green>✓</> Original HTML length: {$originalLength} chars");
                $this->line("<fg=green>✓</> Processed HTML length: {$processedLength} chars");
                $this->line('<fg=green>✓</> Has rich content: '.($contentMigrator->hasRichContent($processed) ? 'Yes' : 'No'));

                $shortDesc = $contentMigrator->extractPlainText($processed, 150);
                $this->line("<fg=green>✓</> Generated short description: {$shortDesc}");
            }
        }

        $this->newLine();
        $this->info('✅ Analysis complete!');

        return self::SUCCESS;
    }

    protected function percentage(int $value, int $total): string
    {
        if ($total === 0) {
            return '0%';
        }

        return round(($value / $total) * 100, 1).'%';
    }

    protected function createMockImageMigrator(): ImageMigrator
    {
        // Create a mock that doesn't actually migrate images
        return new class extends ImageMigrator
        {
            public function __construct()
            {
                // Skip parent constructor
            }

            public function migrateFromUrl(string $imageUrl, string $altText = ''): ?int
            {
                return 999; // Mock image ID
            }

            public function getWordPressMediaUrl(int $mediaId): ?string
            {
                return 'https://example.com/wp-content/uploads/mock-image.jpg';
            }
        };
    }
}

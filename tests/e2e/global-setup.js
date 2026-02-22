import { exec } from 'child_process';
import { promisify } from 'util';

const execAsync = promisify(exec);

async function globalSetup() {
    // Seed a test migration run for E2E tests
    const seedCommand = `php artisan tinker --execute="
        use App\\Models\\MigrationRun;
        use App\\Models\\MigrationLog;
        use App\\Models\\MigrationEntity;

        // Clear previous E2E data
        MigrationLog::query()->delete();
        MigrationEntity::query()->delete();
        MigrationRun::query()->delete();

        // Create a test migration run
        \\$m = MigrationRun::create([
            'name' => 'E2E Test Migration',
            'status' => 'running',
            'started_at' => now(),
            'settings' => [
                'shopware' => [
                    'db_host' => 'localhost',
                    'db_port' => 3306,
                    'db_database' => 'shopware',
                    'db_username' => 'root',
                    'db_password' => 'secret',
                    'base_url' => 'https://shop.example.com',
                    'language_id' => 'abc123def456',
                    'live_version_id' => 'def456abc123',
                ],
                'woocommerce' => [
                    'base_url' => 'https://woo.example.com',
                    'consumer_key' => 'ck_test_key',
                    'consumer_secret' => 'cs_test_secret',
                ],
                'wordpress' => [
                    'username' => 'admin',
                    'app_password' => 'app_pass_123',
                ],
            ],
        ]);

        // Create some sample entities
        \\$m->entities()->create([
            'entity_type' => 'category',
            'shopware_id' => 'cat001',
            'woo_id' => 10,
            'status' => 'success',
        ]);

        \\$m->entities()->create([
            'entity_type' => 'product',
            'shopware_id' => 'prod001',
            'status' => 'pending',
        ]);

        \\$m->entities()->create([
            'entity_type' => 'product',
            'shopware_id' => 'prod002',
            'woo_id' => 20,
            'status' => 'success',
        ]);

        \\$m->entities()->create([
            'entity_type' => 'product',
            'shopware_id' => 'prod003',
            'status' => 'failed',
            'error_message' => 'API timeout',
        ]);

        // Create some sample logs
        \\$m->logs()->create([
            'entity_type' => 'category',
            'shopware_id' => 'cat001',
            'level' => 'info',
            'message' => 'Category migrated successfully',
        ]);

        \\$m->logs()->create([
            'entity_type' => 'product',
            'shopware_id' => 'prod003',
            'level' => 'error',
            'message' => 'Failed to migrate product: API timeout',
        ]);

        \\$m->logs()->create([
            'entity_type' => 'product',
            'level' => 'warning',
            'message' => 'Product has no images',
        ]);

        echo \\$m->id;
    "`;

    const { stdout } = await execAsync(seedCommand);
    const migrationId = stdout.trim();

    // Store the migration ID so tests can use it
    process.env.E2E_MIGRATION_ID = migrationId;
}

export default globalSetup;

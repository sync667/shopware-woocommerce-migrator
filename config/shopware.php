<?php

/*
|--------------------------------------------------------------------------
| Shopware Configuration (Defaults)
|--------------------------------------------------------------------------
|
| These values serve as defaults for the Shopware source DB connection.
| In production, each migration run stores its own connection settings
| in the migration_runs.settings JSON column, configured via the dashboard.
|
*/

return [
    'db' => [
        'host' => env('SHOPWARE_DB_HOST', '127.0.0.1'),
        'port' => env('SHOPWARE_DB_PORT', 3306),
        'database' => env('SHOPWARE_DB_DATABASE', ''),
        'username' => env('SHOPWARE_DB_USERNAME', ''),
        'password' => env('SHOPWARE_DB_PASSWORD', ''),
    ],
    'language_id' => env('SHOPWARE_LANGUAGE_ID', ''),
    'live_version_id' => env('SHOPWARE_LIVE_VERSION_ID', ''),
    'base_url' => env('SHOPWARE_BASE_URL', ''),
];

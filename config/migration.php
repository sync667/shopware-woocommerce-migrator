<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Redirection plugin integration
    |--------------------------------------------------------------------------
    |
    | Configuration for pushing Shopware SEO URLs as 301 redirects into the
    | WordPress Redirection plugin (https://redirection.me/).
    |
    */

    'redirection' => [
        'enabled' => env('MIGRATION_REDIRECTION_ENABLED', true),
        'group_name' => env('MIGRATION_REDIRECTION_GROUP_NAME', 'Shopware Migration'),
        'default_code' => (int) env('MIGRATION_REDIRECTION_DEFAULT_CODE', 301),
    ],

];

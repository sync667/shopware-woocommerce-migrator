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

    /*
    |--------------------------------------------------------------------------
    | Category SEO long-text migration
    |--------------------------------------------------------------------------
    |
    | Controls how Shopware 6 category SEO copy ("text below products") is
    | migrated into WooCommerce term descriptions. The resolver looks first at
    | the configured custom-field key on category_translation.custom_fields,
    | then falls back to the linked CMS page's text/HTML slots.
    |
    */

    'category_seo' => [
        'custom_field_key' => env('SHOPWARE_CATEGORY_SEO_CUSTOM_FIELD', 'custom_seo_text_below'),
        'enabled' => env('SHOPWARE_CATEGORY_SEO_ENABLED', true),
    ],

];

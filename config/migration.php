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

    /*
    |--------------------------------------------------------------------------
    | Product visibility — primary sales channel
    |--------------------------------------------------------------------------
    |
    | Scopes product catalog_visibility to one channel name. Null = MAX across
    | all channels (single-channel shops).
    |
    */

    'primary_sales_channel' => env('MIGRATION_PRIMARY_SALES_CHANNEL'),

    /*
    |--------------------------------------------------------------------------
    | Companion plugin integration
    |--------------------------------------------------------------------------
    |
    | Optional extension point for forwarding Shopware-side product data into
    | postmeta keys that a custom WP/WC companion plugin reads. Every key here
    | is env-overridable so site-specific naming stays out of the public repo.
    |
    | shopware_tier_field  source custom field on product_translation.custom_fields
    |                      whose value is JSON [{quantityFrom, quantityTo, grossPrice}]
    | meta.*               WC postmeta keys the companion plugin reads
    |
    */

    'companion' => [
        'shopware_tier_field' => env('COMPANION_SHOPWARE_TIER_FIELD', 'shipping_tiers'),
        'meta' => [
            'block_purchase' => env('COMPANION_META_BLOCK_PURCHASE', '_custom_block_purchase'),
            'delivery_tiers' => env('COMPANION_META_DELIVERY_TIERS', '_custom_delivery_tiers'),
            'email_original' => env('COMPANION_META_EMAIL_ORIGINAL', '_custom_email_original'),
            'email_aliased' => env('COMPANION_META_EMAIL_ALIASED', '_custom_email_aliased'),
        ],
    ],

];

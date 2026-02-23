# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.2.0](https://github.com/sync667/shopware-woocommerce-migrator/compare/0.1.0...v0.2.0) (2026-02-23)


### Features

* add cancellation support for migration jobs and enhance supervisor configurations ([07e3f77](https://github.com/sync667/shopware-woocommerce-migrator/commit/07e3f77932cf3ac81edc40ca97661dd8656041d5))
* implement session management and access token validation for authentication ([7a15bab](https://github.com/sync667/shopware-woocommerce-migrator/commit/7a15bab93ff2524176889deccecbb88d7f177a55))

## [Unreleased]

### Features

- Complete migration tool for Shopware 6 to WooCommerce
- Support for migrating products, categories, customers, orders, reviews, coupons
- Support for shipping methods, payment methods, and SEO URLs
- CMS pages migration with selective page selection
- SSH tunnel support for secure database access
- Delta migration mode for incremental updates
- Conflict resolution strategies (Shopware wins, WooCommerce wins, Manual)
- Clean WooCommerce option for fresh migrations
- Dry run mode for testing migrations
- Real-time migration progress tracking
- Comprehensive logging system
- Connection testing for all services
- WordPress media upload integration
- Interactive help guides for credential setup
- Automatic language and version ID detection from Shopware database

### Documentation

- Setup guides for WooCommerce API keys
- Setup guides for WordPress Application Passwords
- Setup guides for Shopware database configuration
- Setup guides for SSH tunnel configuration
- Docker-based development environment documentation

# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.3.0](https://github.com/sync667/shopware-woocommerce-migrator/compare/v0.2.0...v0.3.0) (2026-02-25)


### Features

* add database dump upload as alternative to direct DB connection ([a56c7f6](https://github.com/sync667/shopware-woocommerce-migrator/commit/a56c7f695d34320e4a9c02c2ec2492d3c6b55d98))
* Add database dump upload as alternative to direct Shopware DB connection ([e09db6b](https://github.com/sync667/shopware-woocommerce-migrator/commit/e09db6b35936af4b41fc075c7ea802a25aa08197))
* create job_batches table for managing job batch processing ([f492152](https://github.com/sync667/shopware-woocommerce-migrator/commit/f4921523874e98b9c66a5a04a49f655e0f37faed))
* enhance database connection management and job processing configurations ([bde1341](https://github.com/sync667/shopware-woocommerce-migrator/commit/bde134174730194bf402433014ccc2f9d0aa1cff))
* implement product streams migration and enhance product handling in migration jobs ([e995958](https://github.com/sync667/shopware-woocommerce-migrator/commit/e995958ac7d2c19e160fe4018384518415100eb2))
* update environment configuration and enhance port management for Docker services ([0a91a64](https://github.com/sync667/shopware-woocommerce-migrator/commit/0a91a64b20399143a2e43d8a1e00d7c21ee0b4d6))


### Bug Fixes

* address code review - fix command injection risks, file handling bugs, and improve tests ([71e4734](https://github.com/sync667/shopware-woocommerce-migrator/commit/71e4734db3d9a24f6298281b94e5566860dea1ec))
* address PR review - file cleanup, container cleanup, streaming import, path traversal protection ([cf94ac0](https://github.com/sync667/shopware-woocommerce-migrator/commit/cf94ac0d4803020fdc973b51a967978af36e8733))
* dry run progress not advancing - use skipped status instead of pending ([7892d54](https://github.com/sync667/shopware-woocommerce-migrator/commit/7892d54014807ea5cafc336ac5a6f89abebd744f))
* ensure normal migration works correctly ([3af8db6](https://github.com/sync667/shopware-woocommerce-migrator/commit/3af8db640d9ed4126dbff015bde2159eb65d7382))
* harden normalizePath against empty array, add try-finally for file handle, improve test assertion ([6463f69](https://github.com/sync667/shopware-woocommerce-migrator/commit/6463f694d49cc210fa3713d1f96a3393b0ca311f))
* improve test comments per code review feedback ([7a9e821](https://github.com/sync667/shopware-woocommerce-migrator/commit/7a9e821d63e86b3497401375d2fdc8020120e957))
* resolve migration stalling at products with pending status ([a025d4b](https://github.com/sync667/shopware-woocommerce-migrator/commit/a025d4b297328496fbefd569afab819b2fb4ec97))
* update shipping method name retrieval and change database port in configuration ([1a1b989](https://github.com/sync667/shopware-woocommerce-migrator/commit/1a1b98927f422fc12448d5ef017a25b406cd4b22))


### Performance Improvements

* chunked batch processing for products, orders, coupons, reviews ([946e18c](https://github.com/sync667/shopware-woocommerce-migrator/commit/946e18cd802727ac4463232ab955cc7a29651e9c))

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

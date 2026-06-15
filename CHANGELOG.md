# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.6.0](https://github.com/sync667/shopware-woocommerce-migrator/compare/v0.5.0...v0.6.0) (2026-06-15)


### Features

* add support for newsletter recipients and customer wishlists migration ([f1767f8](https://github.com/sync667/shopware-woocommerce-migrator/commit/f1767f85edb486405937ad6616cb184376743df2))
* clean idea ([f0664ae](https://github.com/sync667/shopware-woocommerce-migrator/commit/f0664ae705f4c70ea55912bd20ff959d5d83c1e2))
* enhance category SEO migration with custom field resolution and description formatting ([8d9c792](https://github.com/sync667/shopware-woocommerce-migrator/commit/8d9c7924d2127c31fb832492461e897cac3b11ef))
* enhance product attribute migration with slug handling and idempotency checks ([077fc6c](https://github.com/sync667/shopware-woocommerce-migrator/commit/077fc6c28a0fc127f097c7bb29b755ad1f261c8d))
* enhance SEO URL migration with Redirection plugin integration and add CSV logging ([07d766b](https://github.com/sync667/shopware-woocommerce-migrator/commit/07d766bcc5d1c52261c196481bbc5a05153da3a7))
* implement batch processing for category, manufacturer, product attribute migrations and add PrepareCatalogJob ([62c196f](https://github.com/sync667/shopware-woocommerce-migrator/commit/62c196f032a78729250c7f4941f68fe034e3378e))
* implement batch processing for linking cross-sells and migrating product attributes ([4939f95](https://github.com/sync667/shopware-woocommerce-migrator/commit/4939f95cf07e7cc6066e7a318d9a12ad3f143284))
* implement chunked upload for database dumps and enhance container management ([29eeecf](https://github.com/sync667/shopware-woocommerce-migrator/commit/29eeecf647b171d070431a14a907526d784d501b))
* implement email sanitization and aliasing for customer migration ([ddf58ae](https://github.com/sync667/shopware-woocommerce-migrator/commit/ddf58ae8f236d5722f1816143085c46d5e2418fc))
* integrate companion plugin support for Shopware product data migration ([bb1e359](https://github.com/sync667/shopware-woocommerce-migrator/commit/bb1e359271b75b196d3d160fba121a84b41b382b))
* link cross-sells in a dedicated post-products job ([4c98cd3](https://github.com/sync667/shopware-woocommerce-migrator/commit/4c98cd31f0527e66440694551218e2728bebfdf9))
* migrate per-product Shopware delivery tiers to RemizaSklep meta ([0e5bf4e](https://github.com/sync667/shopware-woocommerce-migrator/commit/0e5bf4e0f8bc233f9cc12f6b69c9807763f7b096))
* optimize migration job queues and enhance memory management for large data sets ([3684247](https://github.com/sync667/shopware-woocommerce-migrator/commit/3684247943b6623d87cdec60e01e27f0913a34ea))
* preserve Shopware variant ordering via WC menu_order ([1b0ab92](https://github.com/sync667/shopware-woocommerce-migrator/commit/1b0ab925856464e69ad8e9deeb7c4ea6986c8293))
* refactor authentication tests to improve token validation and session handling ([2c1f84a](https://github.com/sync667/shopware-woocommerce-migrator/commit/2c1f84ad974c382b77be5f00575bf09cf5588cc8))
* scope product visibility, SEO keywords, main variant, delivery time ([a8bac76](https://github.com/sync667/shopware-woocommerce-migrator/commit/a8bac765001a88278250c396b1308e85b490dbbc))
* stamp _remizasklep_block_purchase for closeout stock-outs ([7dda5b3](https://github.com/sync667/shopware-woocommerce-migrator/commit/7dda5b30c5eb66ead611949e15fe2f8405a41475))
* surface new migration features in Settings UI ([6113663](https://github.com/sync667/shopware-woocommerce-migrator/commit/6113663d1e7aa5f319f33a6e7298ce3b3b6edb4b))
* WooCommerceDB service + preserve order ids + status history ([73adbab](https://github.com/sync667/shopware-woocommerce-migrator/commit/73adbab0e34ad4ba6b7043b533a0e38f6ad9c258))


### Bug Fixes

* preserve description formatting on the DOM, not via strip_tags ([d354012](https://github.com/sync667/shopware-woocommerce-migrator/commit/d354012804487ed5221926db1ce112eb3cd784fa))
* route category + CMS descriptions through ContentMigrator ([89b671e](https://github.com/sync667/shopware-woocommerce-migrator/commit/89b671e14b4d102ed3a8f232de2089f4f7e29806))

## [0.5.0](https://github.com/sync667/shopware-woocommerce-migrator/compare/v0.4.0...v0.5.0) (2026-02-27)


### Features

* implement WooCommerce email notification management during migration ([343fc0e](https://github.com/sync667/shopware-woocommerce-migrator/commit/343fc0ed93792c961e5898477638d7cd66730d8c))
* update environment configuration and API documentation for Shopware to WooCommerce migration ([3ded5e7](https://github.com/sync667/shopware-woocommerce-migrator/commit/3ded5e7bece4b052cf251c54b59528d85373134a))

## [0.4.0](https://github.com/sync667/shopware-woocommerce-migrator/compare/v0.3.0...v0.4.0) (2026-02-26)


### Features

* add media path to category reader and update workspace configuration ([f6e890d](https://github.com/sync667/shopware-woocommerce-migrator/commit/f6e890d4ebb57100a3397e58478e5edab887717f))
* correct linting ([754a92a](https://github.com/sync667/shopware-woocommerce-migrator/commit/754a92afe1e116b1edfde8a748abdf2a02a6947a))
* enhance tax and product migration with improved handling of media and custom headers ([a0195f6](https://github.com/sync667/shopware-woocommerce-migrator/commit/a0195f6b2ea811fa24f3e6dcd7e322ecf805c22f))
* increase timeout and process limits for media and product cleanup ([0768e70](https://github.com/sync667/shopware-woocommerce-migrator/commit/0768e70bb2c776a86d12f4325fd51df9895d8e33))

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

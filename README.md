# Shopware 6 → WooCommerce Migration Tool

A Laravel 12 web application with an Inertia.js + React dashboard that migrates data from a Shopware 6 MySQL database to a WooCommerce store via REST API.

## Features

**Core migration**
- **Ordered pipeline:** Manufacturers → Taxes → Categories → Products (+ variants, media, cross-sells, CMS videos, size charts) → Customers → Orders → Coupons → Reviews → Shipping Methods → Payment Methods → SEO URLs → (optional: CMS Pages, Product Streams, Newsletter, Wishlists)
- **Per-migration settings:** every run stores its own source/target config and feature toggles
- **Multi-storefront / multi-version safe:** all readers filter by `liveVersionIdBin()`; SEO URLs are deduplicated per `(path, foreign_key)` so multi-sales-channel shops don't produce duplicate redirects
- **Async job processing:** Laravel Horizon queues with retry/backoff; `heavy` queue for cleanup and `products`/`customers`/`orders`/`coupons`/`reviews` queues for batched entities
- **Resumable + idempotent:** failed or cancelled migrations can be re-run; already-migrated entities short out, retried order POSTs look up `_shopware_order_id` meta before re-creating
- **Dry run mode:** preview without writing to WooCommerce
- **Delta mode:** migrate only records changed since the last successful sync (watermark advances only after the whole batch completes)
- **Conflict resolution:** Shopware wins / WooCommerce wins / Manual review strategies

**Optional features** (operator opt-in)
- **CMS Pages:** all or selected Shopware Experience World pages → WordPress pages
- **Product Streams:** dynamic groups → static WC categories
- **Omnibus pricing (Polish DSP):** reads `crehler_omnibus_prices` and writes `_omnibus_lowest_price` meta for the WooCommerce PL Omnibus plugin
- **Newsletter export:** writes `storage/app/migrations/{id}/newsletter_recipients.csv` for MailPoet/Mailchimp/Klaviyo import
- **Wishlist export:** flat CSV (one row per customer × product) for YITH or TI WooCommerce Wishlist
- **Companion plugin integration:** forwards Shopware-side product data into postmeta keys a site-specific WP/WC plugin reads. Meta-key names and the source custom-field name are env-configurable so site-specific naming stays out of the public repo. See [Companion plugin extension](#companion-plugin-extension) below.

**Safety**
- **Cross-run media reuse:** the image migrator tracks every Shopware-media-id → WP-attachment-id mapping in `MigrationEntity`. Subsequent runs reuse existing attachments instead of re-uploading.
- **Cleanup safety modes:** WP media library is **not** wiped by default; opt-in either to *migrator-tracked attachments only* (preserves blog images, theme demos, hand-curated content) or full nuke
- **Delta+cleanup blocked:** controller returns 422 when these are combined (cleanup would erase data the delta has no chance to repopulate)
- **Pages cleanup gated on CMS migration:** when CMS pages aren't being migrated, the `pages` cleanup step is never enqueued at all

**Plumbing**
- **Real-time dashboard:** live per-entity status cards, current step, ETA, last activity, recent errors/warnings
- **Image migration:** downloads from Shopware (with anti-adblocker URL rewriting), validates real MIME via finfo, aligns extension, uploads to WP Media Library
- **Product CMS videos:** YouTube/Vimeo slots from the product's CMS layout are appended to the description as Gutenberg `wp:embed` blocks. Per-product `product_translation.slot_config` overrides take precedence over the shared layout default, so each product keeps its own video.
- **Size chart ("Rozmiarówka"):** the size-chart media custom field (`migration.size_chart.custom_field`) is uploaded to the WP Media Library (images **and** PDFs) and exposed on the product as `_size_chart_image_id` + `_size_chart_image_url` meta for the theme to render
- **Password migration:** Shopware bcrypt hash preserved as `_shopware_password_hash` user meta + legacy SW5 hash + encoder preserved separately; customers get a forced reset on first login
- **SSH tunnel:** connect to Shopware MySQL via a jump host (password or key)
- **SQL dump upload:** import directly from a `.sql` / `.sql.gz` dump instead of connecting to Shopware live

## Companion plugin extension

Some shops run a custom WP/WC plugin that reads its own postmeta keys to drive
storefront behavior — e.g. a "block add-to-cart on closeout stock-out" rule,
per-product delivery-tier pricing, or membership-aware pricing. The migrator
exposes a small set of opt-in hooks that forward Shopware-side data into the
postmeta keys your plugin reads. **No companion plugin is required to use the
migrator** — leave the section disabled and nothing changes.

The integration has three pieces:

1. **Settings.jsx → Companion plugin integration disclosure** (collapsed by
   default). Two checkboxes:
   - **Block purchase on closeout stock-out** — stamps
     `COMPANION_META_BLOCK_PURCHASE = "yes"` on products and variations matching
     `stock ≤ 0 AND is_closeout = 1`. Parent value overrides variants.
   - **Migrate per-product delivery tiers** — reads the
     `COMPANION_SHOPWARE_TIER_FIELD` custom field on each Shopware product,
     validates each `{quantityFrom, quantityTo, grossPrice}` row, and writes
     the result as JSON into the `COMPANION_META_DELIVERY_TIERS` postmeta key.
     Uses DELETE-then-INSERT so re-runs are idempotent. Requires Direct MySQL
     access.

2. **Email aliasing.** When a Shopware shop has duplicate emails (e.g. shared
   family/business accounts) the migrator can't create both as WC users —
   WC enforces email uniqueness. The migrator suffixes collisions with
   `+sw_<8hex>@domain` so all customers land in WC. The original email is
   stamped on `COMPANION_META_EMAIL_ORIGINAL` and `COMPANION_META_EMAIL_ALIASED
   = "yes"` so the plugin (or an operator query) can recover the original.

3. **Configuration via env.** Every key is env-overridable so site-specific
   naming stays out of the public repo:

   ```env
   COMPANION_SHOPWARE_TIER_FIELD=shipping_tiers
   COMPANION_META_BLOCK_PURCHASE=_custom_block_purchase
   COMPANION_META_DELIVERY_TIERS=_custom_delivery_tiers
   COMPANION_META_EMAIL_ORIGINAL=_custom_email_original
   COMPANION_META_EMAIL_ALIASED=_custom_email_aliased
   ```

   Defaults are generic (`_custom_*`); override per your plugin's contract.

## Tech Stack

| Layer | Technology |
|-------|-----------|
| Backend | Laravel 12, PHP 8.4+ |
| Frontend | Inertia.js, React 19, Tailwind CSS |
| Queue | Laravel Queues (Redis or database driver) |
| App database | MySQL 8.0 |
| Source | Shopware 6 MySQL (direct TCP or SSH tunnel) |
| Target | WooCommerce REST API v3, WordPress Media API |

## Quick Start (Docker — Recommended)

```bash
git clone https://github.com/sync667/shopware-woocommerce-migrator.git
cd shopware-woocommerce-migrator

# Copy Docker env file and configure ports if needed
cp docker/local/.env.example docker/local/.env

# Copy application env file
cp .env.example .env

# One-command setup: builds images, starts services, installs deps, runs migrations
./local.sh setup
```

Once running, visit **http://localhost:8780**.

> The Vite hot-reload dev server is available at **http://localhost:8773** (started automatically by the `node` container).

For all Docker helper commands, run:

```bash
./local.sh help
```

See [`docker/local/README.md`](docker/local/README.md) for full details.

## Manual Installation

```bash
# Clone the repository
git clone https://github.com/sync667/shopware-woocommerce-migrator.git
cd shopware-woocommerce-migrator

# Install PHP dependencies
composer install

# Install Node.js dependencies
npm install

# Copy environment file
cp .env.example .env

# Generate application key
php artisan key:generate

# Configure your application database in .env (MySQL)
# DB_CONNECTION=mysql
# DB_HOST=127.0.0.1
# DB_PORT=3306
# DB_DATABASE=migrator_app
# DB_USERNAME=root
# DB_PASSWORD=your_password

# Run database migrations
php artisan migrate

# Build frontend assets
npm run build

# Start the development server
php artisan serve
```

## Requirements

- PHP 8.4+ with `pdo_mysql` extension
- Composer
- Node.js 20+
- MySQL 8.0 (application database)
- Redis (recommended for queues) or database queue driver
- Network access to Shopware MySQL and WooCommerce REST API

## Queue Worker

Migration jobs require a running queue worker:

```bash
# Using Redis (recommended)
php artisan queue:work redis --tries=3 --backoff=5

# For parallel product processing, run a separate worker for the products queue:
php artisan queue:work redis --queue=products --tries=3 --backoff=5

# Using Laravel Horizon (if configured)
php artisan horizon
```

## Usage

### Web Dashboard

1. Visit `http://localhost:8780` to see the dashboard
2. Click **New Migration** to configure source/target connections
3. Fill in Shopware DB, WooCommerce API, and WordPress Media credentials
4. Use **Test Connection** buttons to verify connectivity
5. Click **Dry Run** to preview or **Start Migration** to begin
6. Monitor progress in real-time on the migration detail page
7. View detailed logs with filtering and CSV export

### CLI

```bash
php artisan shopware:migrate \
  --name="Full Migration" \
  --sw-host=shopware-db.example.com \
  --sw-database=shopware \
  --sw-username=root \
  --sw-password=secret \
  --sw-language-id=YOUR_LANGUAGE_HEX \
  --sw-version-id=YOUR_VERSION_HEX \
  --sw-base-url=https://shop.example.com \
  --wc-base-url=https://woo.example.com \
  --wc-key=ck_your_key \
  --wc-secret=cs_your_secret \
  --wp-username=admin \
  --wp-app-password=your_app_password

# Dry run mode — previews without writing to WooCommerce
php artisan shopware:migrate --dry-run [... other options]

# Delta mode — migrate only records changed since the last run
php artisan shopware:migrate --mode=delta --conflict=shopware_wins [... other options]
# --conflict accepts: shopware_wins (default), woo_wins, manual

# SSH tunnel — connect to Shopware DB via a jump host
php artisan shopware:migrate \
  --ssh-host=jump.example.com \
  --ssh-username=deploy \
  --ssh-key=/path/to/id_rsa \
  [... other options]

# CMS pages migration
php artisan shopware:migrate --cms-all [... other options]
php artisan shopware:migrate --cms-ids=abc123,def456 [... other options]
```

> **Note:** the optional features added in recent releases — Omnibus pricing, newsletter export, wishlist export, cleanup-safety modes (`delete_media`, `media_mode`) — are wired through the dashboard / `POST /api/migrations` payload but not yet exposed as `php artisan shopware:migrate` flags. Use the web UI to enable them, or POST directly to the API.

### Post-migration repair commands

One-off, idempotent commands for repairing data after a run. All accept `--dry-run`.

```bash
# Repair WC order dates (created/updated/paid/completed) from Shopware
php artisan shopware:fix-order-dates {migration} [--dry-run]

# Backfill product size-chart images/PDFs (Rozmiarówka) → WP media + _size_chart_image_{id,url} meta.
# Use --sw-base-url / --wc-url when the source or target host has moved since the run
# (stored base URLs go stale after go-live).
php artisan shopware:backfill-size-charts {migration} \
  --sw-base-url=https://old.example.com \
  --wc-url=https://shop.example.com [--dry-run] [--limit=0]
```

## Migration Steps

The chain runs serially via `Bus::chain`, with each batched stage dispatching the next inside its `then()` callback:

| Step | Entity | Required? | Depends On |
|------|--------|-----------|------------|
| 0 | Cleanup (configurable: orders, reviews, coupons, products, attributes, tags, categories, customers, taxes, shipping zones, pages, media) | opt-in | — |
| 1 | Manufacturers | yes | — |
| 2 | Tax Classes + Rates | yes | — |
| 3 | Categories | yes | — |
| 4 | Products (+ media + variants + main_category + cross-sells) | yes | Categories, Manufacturers, Taxes |
| 5 | Customers | yes | — |
| 6 | Orders | yes | Products, Customers |
| 7 | Coupons | yes | — |
| 8 | Reviews | yes | Products, Customers |
| 9 | Shipping Methods | yes | — |
| 10 | Payment Methods | yes | — |
| 11 | SEO URLs (Redirection plugin + CSV) | yes | Products, Categories, CMS Pages |
| 12 | CMS Pages | opt-in (`cms_options`) | — |
| 13 | Product Streams → WC categories | opt-in (`stream_options`) | Products |
| 14 | Newsletter recipients → CSV | opt-in (`newsletter_options`) | — |
| 15 | Customer wishlists → CSV | opt-in (`wishlist_options`) | Customers, Products |

## Entity Coverage

- **Products:** name, SKU, descriptions, prices (regular + sale from `listPrice`, formatted with `number_format` to avoid float drift), stock (`manage_stock=true` always; `stock_status` derived from `is_closeout`+stock+available — fixes the inverted Shopware-6 semantics), weight (g→kg), dimensions (mm→cm), tax class, categories, tags, attributes (variant + descriptive), up-sells/cross-sells, images, variants. Variants carry `parent_woo_id` payload so order line items get both `product_id` and `variation_id`. Also: `purchase_prices` → `_wc_cog_cost` (Cost of Goods plugin), `release_date`, main_category → `_yoast_wpseo_primary_product_cat`, Omnibus lowest price (when opted in).
- **Product Streams (opt-in):** Shopware dynamic product groups → WooCommerce product categories
- **Categories:** name, description, sort order, images (with cross-run reuse), hierarchy (`level`-ordered in delta mode so parents migrate before children), Yoast meta title/description, **SEO long-text below grid** (resolved from `category_translation.custom_fields` or CMS-page slot text via `CategorySeoTextResolver`, version-id-filtered)
- **Customers:** name, email, billing/shipping addresses (with `additional_address_line1` + `line2` concatenated into WC `address_2`, `vat_id` preserved), VAT IDs JSON → `_billing_vat` + `_shopware_vat_ids`, salutation, title, birthday, Shopware bcrypt password hash, legacy SW5 password + encoder, customer company. WC password generated server-side, `_requires_password_reset` flag set so customers reset on first login.
- **Orders:** order number, date, status mapping, shipping line + tracking codes (single meta with all items, fixes WC dedup bug), customer notes, addresses (full set of fields), line items with proper `product_id`/`variation_id` linking, `deep_link_code` for guest-order recovery, affiliate/campaign codes, custom fields. Retries are idempotent via `_shopware_order_id` meta lookup (paginates up to 1000 candidate orders before deciding to POST).
- **Manufacturers:** name, image → WC product attribute terms (version-id-filtered)
- **Tax Classes:** name, rates per country
- **Coupons:** code, discount type/amount (`number_format`-money), date range, usage limits. Individual promotion codes tracked per-code in StateManager so retries don't duplicate.
- **Reviews:** rating, author, comment, product link, approval status
- **Shipping Methods:** name → WooCommerce shipping zones/methods
- **Payment Methods:** name → WooCommerce payment gateways
- **SEO URLs:** Shopware canonical + alias URLs → Redirection plugin rules + CSV export with UTF-8 BOM. Multi-storefront deduplication (`ROW_NUMBER() OVER PARTITION BY seo_path_info, foreign_key`). Source paths percent-encoded for byte-exact browser matching.
- **CMS Pages (opt-in):** Shopware Experience World pages → WordPress pages (all or selected)
- **Newsletter Recipients (opt-in):** streamed CSV export for MailPoet/Mailchimp/Klaviyo import
- **Customer Wishlists (opt-in):** flat CSV (one row per customer × product) for YITH/TI WooCommerce Wishlist
- **Media:** every Shopware media ID → WP attachment ID mapping persisted in `MigrationEntity`. Subsequent runs reuse the attachment (after verifying it still exists in WP) instead of re-uploading.

## Architecture

```
app/
├── Console/Commands/MigrateShopwareCommand.php   # CLI fallback (currently API-parity-light; see CLI section)
├── Http/Controllers/
│   ├── DashboardController.php                    # Dashboard Inertia page
│   ├── MigrationController.php                    # Migration API + status/redaction
│   ├── ShopwareConfigController.php               # Language/version lookups
│   ├── DumpUploadController.php                   # SQL dump upload
│   └── LogController.php                          # Log endpoints
├── Jobs/                                          # Async migration jobs, one per entity type
│                                                  # plus batch variants for large entities
├── Models/                                        # MigrationRun, MigrationEntity, MigrationLog
├── Services/
│   ├── ShopwareDB.php                             # Source DB connection + version/language helpers
│   ├── WooCommerceClient.php                      # WC REST client + order-by-meta lookup
│   ├── WordPressMediaClient.php                   # WP media + pages + comments client
│   ├── ImageMigrator.php                          # Cross-run media reuse + upload pipeline
│   ├── ContentMigrator.php                        # HTML body rewriting for migrated images
│   ├── CategorySeoTextResolver.php                # Reads CMS-slot or custom-field SEO text per category
│   ├── RedirectionClient.php                      # Redirection plugin API client
│   ├── WooCommerceCleanup.php                     # Cleanup orchestration with safety modes
│   ├── StateManager.php                           # MigrationEntity-backed mapping store
│   ├── PasswordMigrator.php                       # Shopware bcrypt / legacy SW5 password handling
│   ├── CancellationService.php                    # Per-migration cancel flag
│   └── SSHTunnel.php                              # ssh -L jump-host tunnel manager
└── Shopware/
    ├── Readers/                                   # Pure DB query classes — no transformation
    └── Transformers/                              # Pure data mapping — no I/O
```

**Data flow per entity:**

```
MigrateXxxJob (queue)
  → Reader::fetchXxx()          reads raw rows from Shopware MySQL (version-id-filtered)
  → Transformer::transform()    maps Shopware fields to WooCommerce shape (pure function)
  → WooCommerceClient::post()   writes to WooCommerce REST API (with idempotency lookup)
  → StateManager::set()         records shopware_id → woo_id mapping for state + retries
```

**Readers** are stateless query objects that translate Shopware's UUID-heavy schema (binary IDs, inherited fields, JSON columns) into plain PHP objects. They filter by `live_version_id` so draft/working versions of categories, CMS pages, products, manufacturers don't pollute migration output.

**Transformers** are pure functions with no database or HTTP calls — easy to unit test in isolation.

**Jobs** orchestrate the two and handle retries, dry-run mode, idempotency lookups, queue routing, and progress logging.

**StateManager** stores `(migration_id, entity_type, shopware_id) → woo_id + payload` rows. The image migrator queries across **all migration runs** (not just the current) so re-running a migration over a WP install with prior runs doesn't re-upload media.

## Cleanup — what gets deleted

Set `clean_woocommerce=true` on a migration to delete WC data before the new import runs. **Cannot be combined with delta mode** (controller returns 422).

| Entity | Always deleted? | Notes |
|---|---|---|
| Orders | yes | all orders, `force=true` |
| Reviews | yes | falls back to WP comments API for orphaned review-comments |
| Coupons | yes | including individual codes |
| Products | yes | wipes variations too (WC stores them as child product rows) |
| Product attributes / tags | yes | |
| Categories | yes | `uncategorized` is preserved (WC core requirement) |
| Customers | yes | `?reassign=0` so orders aren't reassigned to admin |
| Tax rates | yes | |
| Tax classes | yes | built-in `standard`/`reduced-rate`/`zero-rate` preserved |
| Shipping zones | yes | zone 0 "Rest of the World" preserved |
| **WordPress pages** | **only when CMS migration is enabled** | matched against the Shopware page slugs being imported — never touches unrelated WP pages |
| **Media library** | **opt-in** via `cleanup_options.delete_media` | two modes: |
|   | | • `migrated_only` (default + recommended): deletes only WP attachments tracked by past migrator runs — keeps blog images, theme demos, hand-curated heroes |
|   | | • `all`: nukes every file in `wp-content/uploads` — only for fresh/throwaway WP installs |

What's **never** touched by cleanup: WP users that aren't customers, plugins, themes, options, Yoast/SEO settings, posts (only pages), webhooks, the migrator's own MigrationRun/MigrationLog rows, anything in the Shopware source DB.

## Finding Shopware IDs

To find the language and version IDs needed for configuration:

```sql
-- Language ID (e.g., for Polish)
SELECT LOWER(HEX(id)) FROM language WHERE name = 'Polski';

-- Live Version ID
SELECT LOWER(HEX(id)) FROM version WHERE name = 'Live';
```

## API Endpoints

All endpoints require session authentication (cookie from the login page). JSON request/response.

**Migrations**

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/migrations` | Create and start a new migration |
| GET | `/api/migrations/{id}/status` | Poll progress, counts, ETA, current step |
| GET | `/api/migrations/{id}/logs` | Paginated logs (`?level=error&page=1`) |
| POST | `/api/migrations/{id}/pause` | Pause a running migration |
| POST | `/api/migrations/{id}/resume` | Resume a paused migration |
| POST | `/api/migrations/{id}/cancel` | Cancel a running migration |

**Connection testing**

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/shopware/ping` | Test Shopware DB connection |
| POST | `/api/woocommerce/ping` | Test WooCommerce API connection |
| POST | `/api/test-connections` | Test all connections at once |
| POST | `/api/shopware/languages` | List available Shopware languages |
| POST | `/api/shopware/live-version` | Get the Shopware Live version ID |

**Setup helpers**

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/cms-pages/list` | List Shopware CMS pages for selection |
| POST | `/api/product-streams/list` | List Shopware product streams |
| POST | `/api/dump/upload` | Upload a Shopware MySQL dump file |
| POST | `/api/dump/validate` | Validate an uploaded dump |
| POST | `/api/dump/status` | Check dump import status |
| POST | `/api/dump/cleanup` | Remove uploaded dump files |

## Troubleshooting

**Images return `text/html` / Cloudflare Access blocking downloads**

If the Shopware store is behind Cloudflare Access (Zero Trust), the migrator's image downloader receives an HTML sign-in page instead of the image. Add your Cloudflare service token headers under **Settings → Shopware Custom Headers**:

```
CF-Access-Client-Id: your-client-id.access
CF-Access-Client-Secret: your-client-secret
```

**Orders trigger customer/admin email notifications during migration**

The migrator automatically disables WooCommerce email notifications before migrating customers and orders, and re-enables them afterwards. If the process is interrupted mid-migration, emails may remain disabled — re-enable them in **WooCommerce → Settings → Emails**.

**Migration is stuck / queue workers are idle**

Check that Horizon (or a manual queue worker) is running:

```bash
php artisan horizon:status
php artisan queue:work redis --queue=products,orders,customers,default
```

Inspect failed jobs in the Horizon dashboard or with `php artisan queue:failed`.

**Shopware language / version IDs are unknown**

Run these queries directly on the Shopware MySQL database:

```sql
-- Available languages
SELECT LOWER(HEX(id)) AS id, name FROM language;

-- Live version ID
SELECT LOWER(HEX(id)) AS id, name FROM version WHERE name = 'Live';
```

**Cleanup is slow / shows 0 progress for a long time**

Media deletion uses the WordPress REST Batch API (WP 5.6+) in chunks of 25. If the batch endpoint is unavailable, it falls back to individual deletes — which is slower but still correct. Progress updates appear after every 100 items.

In **migrated_only** mode (the default for media cleanup), the count of attachments deleted reflects only those tracked by past migration runs — so a first-time cleanup on a fresh WP install will correctly log `"no previously-migrated attachments tracked — nothing to delete"`.

**Cleanup is silently doing nothing for pages**

`pages` cleanup is only enqueued when the migration is also importing CMS pages (`cms_options.migrate_all=true` or non-empty `cms_options.selected_ids`). When CMS migration is off, the pages cleanup step is skipped on purpose to avoid touching unrelated WP pages.

**`Error response from daemon: ports are not available: ...:8679 -> 127.0.0.1:0` (or similar)**

A Docker Desktop / WSL2 port-binding quirk — Windows's dynamic port exclusion range has temporarily reserved the port. Either change `FORWARD_REDIS_PORT` (or the offending port) in `docker/local/.env` to a free port like `16379`, or restart WSL2 from an elevated PowerShell with `wsl --shutdown` and start Docker Desktop again.

**`php artisan shopware:migrate` command not found**

Run `php artisan list | grep shopware` to verify the command is registered. If not, run `php artisan package:discover` then retry.

## Contributing

Contributions are welcome! Please read [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines on how to open issues, submit pull requests, and run the test suite.

## License

MIT — see [LICENSE](LICENSE) for details.

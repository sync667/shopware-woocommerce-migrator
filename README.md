# Shopware 6 → WooCommerce Migration Tool

A Laravel 12 web application with an Inertia.js + React dashboard that migrates data from a Shopware 6 MySQL database to a WooCommerce store via REST API.

## Features

- **12-step ordered migration:** Manufacturers → Taxes → Categories → Products → Customers → Orders → Coupons → Reviews → Shipping Methods → Payment Methods → SEO URLs → CMS Pages
- **Per-migration settings:** Each migration run stores its own source/target connection config — run multiple migrations with different settings
- **Async job processing:** All migration tasks run via Laravel Queues with retry logic
- **Real-time dashboard:** Live progress tracking with per-entity status cards, error logs, and pause/resume/cancel controls
- **Dry run mode:** Preview what would be migrated without writing to WooCommerce
- **Delta migration mode:** Incremental updates — migrate only records changed since the last run
- **Conflict resolution:** Choose a strategy when the same entity exists in both stores (Shopware wins, WooCommerce wins, or manual)
- **Duplicate handling:** Automatic detection and reuse of existing WooCommerce entities
- **Resumable:** Failed or cancelled migrations can be re-run — already-migrated entities are skipped
- **Image migration:** Downloads images from Shopware and uploads them to WordPress Media Library
- **Password migration:** Supports direct bcrypt hash migration for WordPress ≥ 6.8
- **SSH tunnel support:** Connect to a Shopware database through an SSH jump host
- **Product streams:** Migrates Shopware dynamic product groups as WooCommerce product categories
- **CMS pages:** Selective or full migration of Shopware CMS pages
- **Multi-version Shopware support:** Auto-detects and supports Shopware 6.5, 6.6, and 6.7+

## Shopware Version Compatibility

The tool auto-detects the connected Shopware version by inspecting the database schema and adapts its queries accordingly:

| Shopware Version | Status | Detection Method |
|-----------------|--------|-----------------|
| **6.5.x** | ✅ Supported | Baseline — `product` table exists |
| **6.6.x** | ✅ Supported | `payment_method.technical_name` column exists |
| **6.7.x** | ✅ Supported | `product.type` column exists |

### Version-Specific Behavior

| Feature | 6.5 | 6.6+ | 6.7+ |
|---------|-----|------|------|
| Digital product detection | `product.states` JSON (`is-download`) | `product.states` JSON (`is-download`) | `product.type = 'digital'` |
| Payment method identification | `handler_identifier` only | `handler_identifier` + `technical_name` | `handler_identifier` + `technical_name` |
| Shipping method identification | Name only | Name + `technical_name` | Name + `technical_name` |

Version detection happens automatically on first database connection. The detected version is displayed as a badge in the settings UI and stored with the migration settings. Use `ShopwareDB::isAtLeast('6.6')` in code for version-conditional logic.

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

## Migration Steps

| Step | Entity | Depends On |
|------|--------|------------|
| 1 | Manufacturers | — |
| 2 | Tax Classes + Rates | — |
| 3 | Categories | — |
| 4 | Products (+ media + variants + streams) | Categories, Manufacturers, Taxes |
| 5 | Customers | — |
| 6 | Orders | Products, Customers |
| 7 | Coupons | — |
| 8 | Reviews | Products, Customers |
| 9 | Shipping Methods | — |
| 10 | Payment Methods | — |
| 11 | SEO URLs | Products, Categories |
| 12 | CMS Pages (optional) | — |

## Entity Coverage

- **Products:** Name, SKU, descriptions, prices (regular + sale from `listPrice`), stock, weight (g→kg), dimensions (mm→cm), tax class, categories, tags, attributes (variant + descriptive), up-sells/cross-sells, images, variants
- **Product Streams:** Shopware dynamic product groups → WooCommerce product categories
- **Categories:** Name, description, sort order, images, hierarchy, meta title/description
- **Customers:** Name, email, billing/shipping addresses, password hash migration
- **Orders:** Order number, date, status mapping, line items, addresses, customer notes
- **Manufacturers:** Name, image → WC product attribute terms
- **Tax Classes:** Name, rates per country
- **Coupons:** Code, discount type/amount, date range, usage limits
- **Reviews:** Rating, author, comment, product link, approval status
- **Shipping Methods:** Name → WooCommerce shipping zones/methods
- **Payment Methods:** Name → WooCommerce payment gateways
- **SEO URLs:** Shopware canonical URLs → WooCommerce slugs
- **CMS Pages:** Shopware Experience World pages → WordPress pages (full or selective)

## Architecture

```
app/
├── Console/Commands/MigrateShopwareCommand.php  # CLI fallback
├── Http/Controllers/
│   ├── DashboardController.php                   # Inertia pages
│   ├── MigrationController.php                   # Migration API
│   └── LogController.php                         # Log endpoints
├── Jobs/                                         # Async migration jobs (one per entity type)
├── Models/                                       # MigrationRun, MigrationEntity, MigrationLog
├── Services/                                     # ShopwareDB, WooCommerceClient, StateManager, etc.
└── Shopware/
    ├── Readers/                                  # Pure DB query classes — no transformation
    └── Transformers/                             # Pure data mapping — no I/O
```

**Data flow per entity:**

```
MigrateXxxJob (queue)
  → Reader::fetchXxx()          reads raw rows from Shopware MySQL
  → Transformer::transform()    maps Shopware fields to WooCommerce shape
  → WooCommerceClient::post()   writes to WooCommerce REST API
  → StateManager::set()         records shopware_id → woo_id mapping
```

**Readers** are stateless query objects that translate Shopware's UUID-heavy schema (binary IDs, inherited fields, JSON columns) into plain PHP objects. **Transformers** are pure functions with no database or HTTP calls — easy to unit test in isolation. **Jobs** orchestrate the two and handle retries, dry-run mode, and progress logging.

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

**`php artisan shopware:migrate` command not found**

Run `php artisan list | grep shopware` to verify the command is registered. If not, run `php artisan package:discover` then retry.

## Contributing

Contributions are welcome! Please read [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines on how to open issues, submit pull requests, and run the test suite.

## License

MIT — see [LICENSE](LICENSE) for details.

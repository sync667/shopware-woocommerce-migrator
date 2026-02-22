# Shopware 6.4 → WooCommerce Migration Tool

A Laravel 12 web application with an Inertia.js + React dashboard that migrates data from a Shopware 6.4 MySQL database to a WooCommerce store via REST API.

## Features

- **8-step ordered migration:** Manufacturers → Taxes → Categories → Products → Customers → Orders → Coupons → Reviews
- **Per-migration settings:** Each migration run stores its own source/target connection config — run multiple migrations with different settings
- **Async job processing:** All migration tasks run via Laravel Queues with retry logic
- **Real-time dashboard:** Live progress tracking with per-entity status cards, error logs, and pause/resume/cancel controls
- **Dry run mode:** Preview what would be migrated without writing to WooCommerce
- **Duplicate handling:** Automatic detection and reuse of existing WooCommerce entities
- **Resumable:** Failed or cancelled migrations can be re-run — already-migrated entities are skipped
- **Image migration:** Downloads images from Shopware and uploads them to WordPress Media Library
- **Password migration:** Supports direct bcrypt hash migration for WordPress ≥ 6.8

## Tech Stack

| Layer | Technology |
|-------|-----------|
| Backend | Laravel 12, PHP 8.3+ |
| Frontend | Inertia.js, React 19, Tailwind CSS |
| Queue | Laravel Queues (Redis or database driver) |
| Source | Shopware 6.4 MySQL (direct TCP connection) |
| Target | WooCommerce REST API v3, WordPress Media API |

## Requirements

- PHP 8.3+
- Composer
- Node.js 20+
- PostgreSQL (application database)
- Redis (recommended for queues) or database queue driver
- Network access to Shopware MySQL and WooCommerce REST API

## Installation

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

# Configure your application database in .env (PostgreSQL)
# DB_CONNECTION=pgsql
# DB_HOST=127.0.0.1
# DB_PORT=5432
# DB_DATABASE=migrator
# DB_USERNAME=your_user
# DB_PASSWORD=your_password

# Run database migrations
php artisan migrate

# Build frontend assets
npm run build

# Start the development server
php artisan serve
```

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

1. Visit `http://localhost:8000` to see the dashboard
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

# Dry run mode
php artisan shopware:migrate --dry-run [... other options]
```

## Migration Steps

| Step | Entity | Depends On |
|------|--------|------------|
| 1 | Manufacturers | — |
| 2 | Tax Classes + Rates | — |
| 3 | Categories | — |
| 4 | Products (+ media + variants) | Categories, Manufacturers, Taxes |
| 5 | Customers | — |
| 6 | Orders | Products, Customers |
| 7 | Coupons | — |
| 8 | Reviews | Products, Customers |

## Entity Coverage

- **Products:** Name, SKU, descriptions, prices (regular + sale from `listPrice`), stock, weight (g→kg), dimensions (mm→cm), tax class, categories, tags, attributes (variant + descriptive), up-sells/cross-sells, images, variants
- **Categories:** Name, description, sort order, images, hierarchy, meta title/description
- **Customers:** Name, email, billing/shipping addresses, password hash migration
- **Orders:** Order number, date, status mapping, line items, addresses, customer notes
- **Manufacturers:** Name, image → WC product attribute terms
- **Tax Classes:** Name, rates per country
- **Coupons:** Code, discount type/amount, date range, usage limits
- **Reviews:** Rating, author, comment, product link, approval status

## Architecture

```
app/
├── Console/Commands/MigrateShopwareCommand.php  # CLI fallback
├── Http/Controllers/
│   ├── DashboardController.php                   # Inertia pages
│   ├── MigrationController.php                   # Migration API
│   └── LogController.php                         # Log endpoints
├── Jobs/                                         # Async migration jobs
├── Models/                                       # MigrationRun, MigrationEntity, MigrationLog
├── Services/                                     # ShopwareDB, WooCommerceClient, StateManager, etc.
└── Shopware/
    ├── Readers/                                  # Shopware DB query classes
    └── Transformers/                             # Data transformation (no I/O)
```

## Finding Shopware IDs

To find the language and version IDs needed for configuration:

```sql
-- Language ID (e.g., for Polish)
SELECT LOWER(HEX(id)) FROM language WHERE name = 'Polski';

-- Live Version ID
SELECT LOWER(HEX(id)) FROM version WHERE name = 'Live';
```

## API Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/migrations` | Start a new migration |
| GET | `/api/migrations/{id}/status` | Poll migration progress |
| GET | `/api/migrations/{id}/logs` | Paginated logs (filterable) |
| POST | `/api/migrations/{id}/pause` | Pause migration |
| POST | `/api/migrations/{id}/resume` | Resume migration |
| POST | `/api/migrations/{id}/cancel` | Cancel migration |
| POST | `/api/shopware/ping` | Test Shopware DB connection |
| POST | `/api/woocommerce/ping` | Test WooCommerce API connection |

## License

MIT

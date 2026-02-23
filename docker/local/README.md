# Docker Local Development Environment

Docker setup for the Shopware → WooCommerce Migrator with PHP 8.4, PostgreSQL 17, Redis 7, and Vite hot-reload.

## Services

| Service | Version | Port | Description |
|---------|---------|------|-------------|
| PHP-FPM | 8.4 | 9000 | Application runtime (includes pdo_mysql for Shopware) |
| Nginx | Alpine | 8080 | Web server |
| PostgreSQL | 17 | 5432 | Application database |
| Redis | 7 | 6379 | Cache & queues |
| Node.js | 20 | 5173 | Vite dev server (frontend hot-reload) |
| Mailpit | Latest | 1025/8025 | Email testing |
| Queue Worker | PHP 8.4 | — | Laravel queue worker |
| Scheduler | PHP 8.4 | — | Laravel task scheduler |

## Quick Start

```bash
cd docker/local
cp .env.example .env
chmod +x mc.sh
./mc.sh setup
```

Or from the project root:

```bash
chmod +x local.sh
./local.sh setup
```

## Helper Script (mc.sh)

All commands are available through the `mc.sh` helper script:

```bash
./mc.sh help              # Show all commands

# Container Management
./mc.sh up                # Start containers
./mc.sh down              # Stop containers
./mc.sh restart           # Restart containers
./mc.sh build             # Rebuild (no cache)
./mc.sh ps                # Container status
./mc.sh logs [service]    # View logs
./mc.sh clean             # Remove containers + volumes

# Shell Access
./mc.sh shell             # App container shell
./mc.sh psql              # PostgreSQL CLI
./mc.sh redis             # Redis CLI

# Laravel
./mc.sh artisan <cmd>     # Run artisan command
./mc.sh migrate           # Run migrations
./mc.sh fresh             # Fresh migrations + seed
./mc.sh seed              # Run seeders
./mc.sh test              # Run tests
./mc.sh tinker            # Laravel Tinker
./mc.sh cache             # Clear all caches

# Frontend
./mc.sh npm <cmd>         # Run npm command
./mc.sh npm-build         # Build frontend assets

# Dependencies
./mc.sh composer <cmd>    # Run composer
./mc.sh install           # Install all deps (PHP + Node)
```

## Laravel .env Configuration

Update your main `.env` file:

```env
DB_CONNECTION=pgsql
DB_HOST=pgsql
DB_PORT=5432
DB_DATABASE=laravel
DB_USERNAME=laravel
DB_PASSWORD=secret

REDIS_HOST=redis
REDIS_PASSWORD=null
REDIS_PORT=6379

MAIL_MAILER=smtp
MAIL_HOST=mailpit
MAIL_PORT=1025

CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
```

## Access Points

- **Application**: http://localhost:8080
- **Vite Dev**: http://localhost:5173
- **Mailpit UI**: http://localhost:8025

## PHP Extensions

Included: pdo_mysql (Shopware source), pdo_pgsql, redis, gd (webp/avif), mbstring, exif, pcntl, bcmath, zip, intl, opcache, xdebug

## Xdebug

Pre-configured in `php/php.ini`. IDE settings:
- Port: 9003
- Path mapping: `/var/www/html` → project root

## Troubleshooting

```bash
# Check container status
./mc.sh ps

# View logs
./mc.sh logs
./mc.sh logs api

# Permission issues
docker compose exec api chmod -R 777 storage bootstrap/cache

# Rebuild everything
./mc.sh build
./mc.sh up
```

## Notes

- The PHP-FPM image includes `pdo_mysql` so the app can connect to an external Shopware MySQL database.
- Worker container handles queue processing; Scheduler container handles scheduled tasks.
- Data persists in Docker volumes (pgsql-data, redis-data).
- Non-root user in containers matches host UID/GID.
- **Development only** — not for production use.

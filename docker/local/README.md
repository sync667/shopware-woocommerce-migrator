# Docker Local Development Environment

Docker setup for the Shopware → WooCommerce Migrator with PHP 8.4, PostgreSQL 17, Redis 7, and Vite hot-reload.

## Services

| Service | Version | Host Port | Description |
|---------|---------|-----------|-------------|
| PHP-FPM | 8.4 | 9000 (internal) | Application runtime (includes pdo_mysql for Shopware) |
| Nginx | Alpine | **8780** | Web server |
| MySQL | 8.0 | **8706** | Application database |
| MySQL (test) | 8.0 | **8707** | PHPUnit test database |
| Redis | 7 | **8679** | Cache & queues |
| Node.js | 20 | **8773** | Vite dev server (frontend hot-reload) |
| Mailpit | Latest | **8725** / **8726** | Email testing (SMTP / UI) |
| Horizon | PHP 8.4 | — | Laravel Horizon queue manager |
| Scheduler | PHP 8.4 | — | Laravel task scheduler |

All host ports use the `87xx` prefix to avoid conflicts with other Docker projects running on the same machine. Override any port in `docker/local/.env`.

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
DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=migrator_app
DB_USERNAME=root
DB_PASSWORD=root

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

- **Application**: http://localhost:8780
- **Vite Dev**: http://localhost:8773
- **Mailpit UI**: http://localhost:8726

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

# Docker Local Development Environment

Modern Docker setup for Laravel + Vue.js development with PHP 8.4, PostgreSQL 17, Redis 7, and more.

## Services

| Service | Version | Port | Description |
|---------|---------|------|-------------|
| PHP-FPM | 8.4 | 9000 | Application runtime |
| Nginx | Alpine | 8080 | Web server |
| PostgreSQL | 17 | 5432 | Database |
| Redis | 7 | 6379 | Cache & queues |
| Mailpit | Latest | 1025/8025 | Email testing |
| Node.js | 22 | 5173 | Vite dev server |
| Worker | PHP 8.4 | - | Queue + Scheduler |

## Quick Start

```bash
cd docker/local
cp .env.example .env
chmod +x mc.sh
./mc.sh setup
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

# Dependencies
./mc.sh composer <cmd>    # Run composer
./mc.sh pnpm <cmd>        # Run pnpm
./mc.sh install           # Install all deps
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

Included: pdo_pgsql, redis, gd (webp/avif), mbstring, exif, pcntl, bcmath, zip, intl, opcache, xdebug

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
./mc.sh logs app

# Permission issues
docker compose exec app chmod -R 777 storage bootstrap/cache

# Rebuild everything
./mc.sh build
./mc.sh up
```

## Notes

- Worker container handles both queue processing and scheduled tasks
- Data persists in Docker volumes (pgsql-data, redis-data)
- Non-root user in containers matches host UID/GID
- **Development only** - not for production use


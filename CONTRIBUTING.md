# Contributing to Shopware → WooCommerce Migrator

Thank you for your interest in contributing! This guide explains how to get involved.

## Code of Conduct

Be respectful and constructive. We welcome contributions from everyone.

## How to Contribute

### Reporting Bugs

1. Search [existing issues](https://github.com/sync667/shopware-woocommerce-migrator/issues) first.
2. Open a new issue with:
   - A clear title and description
   - Steps to reproduce
   - Expected vs. actual behaviour
   - Your environment (PHP version, OS, Shopware version, WooCommerce version)

### Suggesting Features

Open an issue with the `enhancement` label. Describe the use-case and the proposed solution.

### Submitting a Pull Request

1. **Fork** the repository and create a branch from `main`:
   ```bash
   git checkout -b feat/your-feature-name
   ```

2. **Set up the development environment** using Docker (recommended):
   ```bash
   cd docker/local
   cp .env.example .env
   ./mc.sh setup
   ```
   Or follow the manual setup in [README.md](README.md).

3. **Make your changes** and ensure:
   - PHP code follows the project style (enforced by Laravel Pint):
     ```bash
     vendor/bin/pint
     ```
   - Frontend code passes ESLint:
     ```bash
     npm run lint
     ```
   - All tests pass:
     ```bash
     php artisan test
     ```
   - Frontend builds without errors:
     ```bash
     npm run build
     ```

4. **Write tests** for new behaviour where applicable. Tests live in `tests/`.

5. **Commit** with a clear, imperative message:
   ```
   feat: add support for Shopware 6.5 language fallback
   fix: handle null category parent in transformer
   docs: update Docker quick-start section
   ```

6. **Open a Pull Request** against `main` and fill in the PR template.

## Development Setup

### Requirements

- Docker Desktop (recommended) **or** PHP 8.3+, Node.js 20+, PostgreSQL 17, Redis 7

### Docker (Recommended)

```bash
cd docker/local
cp .env.example .env
./mc.sh setup        # builds images, starts services, installs deps, runs migrations
./mc.sh test         # run PHP tests
```

### Manual

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm run build
php artisan serve
```

## Project Structure

```
app/
├── Console/Commands/   # CLI commands (MigrateShopwareCommand)
├── Http/Controllers/   # Inertia pages + REST API controllers
├── Jobs/               # Async Laravel Queue jobs per migration step
├── Models/             # MigrationRun, MigrationEntity, MigrationLog
├── Services/           # ShopwareDB, WooCommerceClient, StateManager …
└── Shopware/
    ├── Readers/        # SQL query classes (read-only, no side effects)
    └── Transformers/   # Pure data transformation (no I/O, fully testable)

resources/js/           # React + Inertia frontend (Vite)
docker/local/           # Docker Compose dev environment
tests/
├── Feature/            # HTTP / integration tests
├── Unit/               # Unit tests for transformers
└── e2e/                # Playwright end-to-end tests
```

## Coding Conventions

- **PHP**: PSR-12, enforced by [Laravel Pint](https://laravel.com/docs/pint). Run `vendor/bin/pint` before committing.
- **JavaScript**: ESLint v9 flat config. Run `npm run lint` before committing.
- **Tests**: PHPUnit for PHP, Playwright for E2E.
- **Transformers** must be pure functions (no database/HTTP calls) so they are fully unit-testable.
- **Readers** handle all database queries against Shopware. Keep them thin — pass data to Transformers.

## License

By contributing, you agree that your contributions will be licensed under the [MIT License](LICENSE).

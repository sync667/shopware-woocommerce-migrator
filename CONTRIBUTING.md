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

1. **Fork** the repository and create a branch from `master`:
   ```bash
   git checkout -b feat/your-feature-name
   ```

2. **Set up the development environment** using Docker (recommended):
   ```bash
   # From project root:
   cp docker/local/.env.example docker/local/.env
   ./local.sh setup
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

5. **Commit** with a clear, [Conventional Commit](#conventional-commits) message:
   ```
   feat: add support for Shopware 6.5 language fallback
   fix: handle null category parent in transformer
   docs: update Docker quick-start section
   ```

6. **Open a Pull Request** against `master` and fill in the PR template.

## Conventional Commits

This project uses [Conventional Commits](https://www.conventionalcommits.org/) for automated versioning and changelog generation.

### Commit Message Format

```
<type>[optional scope]: <description>

[optional body]

[optional footer(s)]
```

### Commit Types

| Type | Description | Version Bump | Appears in Changelog |
|------|-------------|--------------|---------------------|
| `feat` | New feature | Minor (0.x.0) | ✅ Features |
| `fix` | Bug fix | Patch (0.0.x) | ✅ Bug Fixes |
| `perf` | Performance improvement | Patch (0.0.x) | ✅ Performance |
| `refactor` | Code refactoring | Patch (0.0.x) | ✅ Refactoring |
| `docs` | Documentation changes | Patch (0.0.x) | ✅ Documentation |
| `style` | Code style changes | None | ❌ |
| `test` | Adding/updating tests | None | ❌ |
| `chore` | Maintenance tasks | None | ❌ |
| `ci` | CI/CD changes | None | ❌ |
| `build` | Build system changes | None | ❌ |

### Breaking Changes

To indicate a **breaking change** (major version bump), add `!` after type or use `BREAKING CHANGE:` footer:

```bash
# Option 1: Exclamation mark
git commit -m "feat!: remove support for PHP 8.2

Minimum PHP version is now 8.3"

# Option 2: Footer
git commit -m "feat: redesign migration API

BREAKING CHANGE: Configuration format has changed. See migration guide."
```

### Commit Examples

```bash
# New feature (minor bump: 0.1.0 → 0.2.0)
git commit -m "feat: add automatic language detection from Shopware database"

# Bug fix (patch bump: 0.1.0 → 0.1.1)
git commit -m "fix: resolve SQL query error in ProductReader"

# Breaking change (major bump: 0.1.0 → 1.0.0)
git commit -m "feat!: redesign configuration API

BREAKING CHANGE: Configuration format changed"

# No release
git commit -m "chore: update dependencies"
git commit -m "test: add unit tests for ProductTransformer"
```

## Release Process

Releases are **fully automated** using GitHub Actions and [release-please](https://github.com/googleapis/release-please):

1. **Commit changes** using conventional commit messages
2. **Push to `master` branch** (the default branch)
3. **Release-please creates a Release PR** automatically:
   - Updates version in `composer.json`
   - Updates `CHANGELOG.md`
   - Creates git tag
4. **Merge the Release PR** (after review)
5. **GitHub Release is created** with release notes and assets

### Version Numbering (Semantic Versioning)

- **Major (X.0.0)**: Breaking changes (`feat!:` or `BREAKING CHANGE:`)
- **Minor (0.X.0)**: New features (`feat:`)
- **Patch (0.0.X)**: Fixes, docs, performance, refactoring (`fix:`, `perf:`, `refactor:`, `docs:`)

## Development Setup

### Requirements

- Docker Desktop (recommended) **or** PHP 8.3+, Node.js 20+, PostgreSQL 17, Redis 7

### Docker (Recommended)

```bash
# From project root:
cp docker/local/.env.example docker/local/.env
./local.sh setup     # builds images, starts services, installs deps, runs migrations
./local.sh test      # run PHP tests
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

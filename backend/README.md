# Backend API

Laravel API backend with authentication, permissions, and queue processing.

## Features

- **Laravel Sanctum** - API token authentication
- **Spatie Permission** - Role and permission management
- **Laravel Horizon** - Queue monitoring and management with main and back queues
- **Spatie MediaLibrary** - Media file management
- **Owen-it Laravel Auditing** - Model change tracking
- **Spatie Laravel Data** - DTOs and data validation
- **Tightenco Ziggy** - Route sharing with frontend

## Requirements

- PHP 8.3+
- PostgreSQL 15+
- Redis

## Installation

```bash
# Install dependencies
composer install

# Copy environment file
cp .env.example .env

# Generate application key
php artisan key:generate

# Run migrations
php artisan migrate

# Seed roles and permissions
php artisan db:seed
```

## Queue Configuration

This project uses Laravel Horizon with two supervisors:

- **supervisor-main** - Handles `default` and `high` priority queues
- **supervisor-back** - Handles `low` and `heavy` priority queues for intensive jobs

Start Horizon with:
```bash
php artisan horizon
```

## Testing

```bash
# Run all tests
php artisan test

# Run with compact output
php artisan test --compact

# Run specific test file
php artisan test tests/Feature/AuthenticationTest.php
```

## Code Style

```bash
# Check code style
vendor/bin/pint --test

# Fix code style
vendor/bin/pint --dirty
```

## API Endpoints

### Authentication

- `POST /api/v1/register` - Register new user
- `POST /api/v1/login` - Login user
- `GET /api/v1/user` - Get authenticated user (requires auth)
- `POST /api/v1/logout` - Logout user (requires auth)

### Utilities

- `GET /api/ziggy` - Get route list for frontend integration

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

# Event-Driven Notification System

A scalable notification system built with Laravel 11 that processes and delivers messages through SMS, Email, and Push channels.

---

## Tech Stack

- **Framework**: PHP Laravel 11
- **Database**: MySQL 8
- **Queue / Cache**: Redis
- **WebSocket**: Laravel Reverb
- **API Docs**: Swagger / OpenAPI (L5-Swagger)
- **Testing**: Pest 3
- **Code Quality**: Laravel Pint, PHPStan (Larastan level 5)
- **Container**: Docker Compose

---

## Setup

**Prerequisites**: Docker & Docker Compose

Set your webhook UUID in `docker-compose.yml`:

```yaml
WEBHOOK_SITE_UUID: "your-uuid-here"
```

Get a free UUID at [webhook.site](https://webhook.site).

Then:

```bash
docker-compose up -d
```

App runs at `http://localhost:8080`. All environment variables are configured in `docker-compose.yml` — no `.env` setup needed.

**Adminer** (MySQL GUI) runs at `http://localhost:8081` — Server: `mysql`, Username: `laravel`, Password: `secret`, Database: `notification_db`.

---

## Testing

Tests use SQLite in-memory — no running Docker services needed.

```bash
# Run all tests
php artisan test

# Run a single test file
php artisan test tests/Unit/NotificationModelTest.php

# Filter by test name
php artisan test --filter="notification casts channel"

# Run only Unit or Feature suite
php artisan test --testsuite=Unit
php artisan test --testsuite=Feature
```

Inside Docker:

```bash
docker-compose exec app php artisan test
```

---

## Code Quality

```bash
# Code style
./vendor/bin/pint
./vendor/bin/pint --test

# Static analysis
./vendor/bin/phpstan analyse

# API docs
php artisan l5-swagger:generate
```

Inside Docker prefix with `docker-compose exec app`.

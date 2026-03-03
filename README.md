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

---

## Commands

```bash
# Tests
docker-compose exec app php vendor/bin/pest
docker-compose exec app php vendor/bin/pest --filter=TestName
docker-compose exec app php artisan test --testsuite=Feature
docker-compose exec app php artisan test --testsuite=Unit

# Code style
docker-compose exec app ./vendor/bin/pint
docker-compose exec app ./vendor/bin/pint --test

# Static analysis
docker-compose exec app ./vendor/bin/phpstan analyse

# API docs
docker-compose exec app php artisan l5-swagger:generate
```

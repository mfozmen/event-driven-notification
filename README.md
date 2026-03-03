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

## Running with Docker

**Prerequisites**: Docker & Docker Compose

```bash
git clone <repo-url>
cd event-driven-notification
cp .env.example .env
# Set WEBHOOK_SITE_UUID in .env
docker-compose up -d
docker-compose exec app composer install
docker-compose exec app php artisan key:generate
docker-compose exec app php artisan migrate
```

App runs at `http://localhost:8080`. Docker starts `app`, `nginx`, `mysql`, and `redis`.

---

## Running Locally

**Prerequisites**: PHP 8.3+, Composer, MySQL 8, Redis

```bash
git clone <repo-url>
cd event-driven-notification
cp .env.example .env
composer install
php artisan key:generate
```

Update `.env` to point to your local services:

```env
DB_HOST=127.0.0.1
DB_DATABASE=notification_db
DB_USERNAME=root
DB_PASSWORD=secret

REDIS_HOST=127.0.0.1

WEBHOOK_SITE_UUID=your-uuid-here
```

```bash
php artisan migrate
php artisan serve
```

App runs at `http://localhost:8000`.

---

## Commands

```bash
# Tests
php vendor/bin/pest
php vendor/bin/pest --filter=TestName
php artisan test --testsuite=Feature
php artisan test --testsuite=Unit

# Code style
./vendor/bin/pint
./vendor/bin/pint --test

# Static analysis
./vendor/bin/phpstan analyse

# API docs
php artisan l5-swagger:generate
```

> Prefix commands with `docker-compose exec app` when running inside Docker.

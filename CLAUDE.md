# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

Event-Driven Notification System for Insider One — a scalable REST API that processes and delivers messages through SMS, Email, and Push channels. Handles high throughput, reliable delivery with retries, and real-time status tracking.

## Tech Stack

- **Framework**: PHP Laravel 11
- **Queue / Cache / Rate Limiting**: Redis
- **Database**: MySQL 8
- **Containerization**: Docker Compose (one-command setup, all env vars in docker-compose.yml)
- **API Docs**: Swagger/OpenAPI via L5-Swagger
- **Testing**: Pest 3 (TDD — tests first, then implement)
- **Code Quality**: Laravel Pint, PHPStan (Larastan level 5)
- **External Provider**: webhook.site (simulates SMS/Email/Push delivery)

## Development Approach

**Test-Driven Development (TDD)** — write tests first, run them (they fail), then implement to make them pass.

## Code Quality Principles

- Write clean, low-complexity methods — extract method refactoring early
- Use design patterns where they fit naturally (Strategy, Factory, etc.) — no overengineering
- Keep methods short and single-purpose
- Prefer readability over cleverness

## User Preferences

- Full control over the code — do not add anything not explicitly asked for
- Never auto-commit
- Go step by step
- After writing tests, pause and let the user commit before proceeding with implementation
- After implementation, pause and let the user commit before moving to the next step
- Never group multiple logical steps into one — always pause between them for a commit
- No `.env` file — all environment variables live in `docker-compose.yml`

## Commands

```bash
# Start
docker-compose up -d

# Tests (SQLite in-memory, no Docker needed)
php artisan test
php artisan test tests/Unit/NotificationModelTest.php
php artisan test --filter="test name"
php artisan test --testsuite=Unit
php artisan test --testsuite=Feature

# Inside Docker prefix with: docker-compose exec app

# Code style
./vendor/bin/pint
./vendor/bin/pint --test

# Static analysis
./vendor/bin/phpstan analyse

# Migrations
docker-compose exec app php artisan migrate

# API docs
docker-compose exec app php artisan l5-swagger:generate
```

## Project Structure

```
app/
  Enums/          # Channel, Priority, Status (backed string enums)
  Models/         # Notification
  Http/
    Controllers/  # API controllers
    Requests/     # Form requests
    Resources/    # API resources
  Services/       # Business logic
  Jobs/           # Queue jobs
  Events/         # Domain events
  Listeners/      # Event listeners
database/
  migrations/
  factories/
routes/
  api.php         # All API routes
  console.php     # Artisan commands
docker/
  nginx/default.conf
  php/local.ini
  entrypoint.sh
```

## Architecture

### Core Domain

- **Notification**: Central entity — recipient, channel (sms/email/push), content, priority (high/normal/low), full status lifecycle
- **Batch**: Groups up to 1000 notifications per request, tracked by `batch_id`
- **Channel Providers**: Strategy pattern — SMS, Email, Push each implement `NotificationChannelInterface`, all POST to webhook.site

### Key Patterns

- **Event-Driven**: `NotificationCreated` event → listener dispatches to priority queue
- **Strategy Pattern**: Channel delivery behind `NotificationChannelInterface`
- **Priority Queues**: `high`, `normal`, `low` Redis queues
- **Rate Limiting**: 100 messages/second/channel via Redis token bucket
- **Idempotency**: Client-provided key prevents duplicate sends
- **Retry + Exponential Backoff**: Candidate-designed, 3 attempts with jitter
- **Correlation IDs**: UUID per request, propagated through jobs and logs

### Status Lifecycle

`pending` → `queued` → `processing` → `delivered`
                                    ↘ `failed` → `retrying` → `delivered`
                                                             ↘ `permanently_failed`
`cancelled` (from `pending` or `queued`)

### API Endpoints

- `POST   /api/notifications`              — create single or batch (up to 1000)
- `GET    /api/notifications/{id}`         — get status
- `GET    /api/notifications`              — list with filters + pagination
- `DELETE /api/notifications/{id}`         — cancel pending
- `GET    /api/notifications/batch/{id}`   — batch status
- `GET    /api/health`                     — health check
- `GET    /api/metrics`                    — queue depth, rates, latency

### External Provider

```
POST https://webhook.site/{WEBHOOK_SITE_UUID}
Body: { "to": "+905551234567", "channel": "sms", "content": "Your message" }
Response 202: { "messageId": "uuid", "status": "accepted", "timestamp": "ISO8601" }
```

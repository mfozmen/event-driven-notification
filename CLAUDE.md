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
- **Code Quality**: Laravel Pint, PHPStan (Larastan level 6)
- **External Provider**: webhook.site (simulates SMS/Email/Push delivery)

## Development Approach

**Test-Driven Development (TDD)** — write tests first, run them (they fail), then implement to make them pass.

## Testing Rules

Every phase must include BOTH unit tests and feature tests. Unit tests cover isolated logic (services, DTOs, enums, strategies, value objects). Feature tests cover HTTP endpoints and full request lifecycle. Never skip unit tests — if a service method has logic, it needs a unit test.

### Testing Conventions

- Never use global `const` in test files. Use `beforeEach` to set up shared data (`$this->service`, `$this->recipient`, etc.)
- Use hardcoded values inline when a test only uses a value once. Only extract to `beforeEach` when the same value is used in 3+ tests
- Factory is the default for creating models in tests. Use `Notification::factory()->create([overrides])` rather than manually building arrays
- For API test payloads (POST body), use inline hardcoded values — they make each test self-contained and readable
- Never depend on constants from other test files
- Keep test files self-contained — a test file should be fully understandable without looking at any other test file

## Code Quality Principles

- Write clean, low-complexity methods — extract method refactoring early
- Use design patterns where they fit naturally (Strategy, Factory, etc.) — no overengineering
- Keep methods short and single-purpose
- Prefer readability over cleverness
- Always run `./vendor/bin/phpstan analyse --memory-limit=512M` after implementation and fix all errors (level 6)

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
  DTOs/           # Data transfer objects (e.g., CreateNotificationResult)
  Enums/          # Channel, Priority, Status (backed string enums)
  Models/         # Notification
  Http/
    Controllers/  # API controllers
    Middleware/   # CorrelationIdMiddleware
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

### Key Patterns & Decisions

- **Event-Driven**: `NotificationCreated` event → listener dispatches to priority queue
- **Strategy Pattern**: Channel delivery behind `NotificationChannelInterface`
- **Priority Queues**: `high`, `normal`, `low` Redis queues
- **Rate Limiting**: 100 messages/second/channel via Redis sliding window
- **Idempotency**: Client-provided key prevents duplicate sends (duplicate returns 200, not 201)
- **Retry + Exponential Backoff**: 3 attempts with jitter, via `$job->release($delay)`
- **Correlation ID**: Generated in `CorrelationIdMiddleware`, passed through request — not generated per notification
- **Cursor-based pagination**: Not offset-based — consistently fast regardless of dataset size
- **DTOs for service returns**: Service methods return typed DTOs, not raw arrays
- **Cancellable statuses**: `pending`, `queued`, `retrying` — anything not yet delivered
- **Separate batch endpoint**: `POST /api/notifications/batch`, not detected in store
- **Cancel via PATCH**: `PATCH /api/notifications/{id}/cancel` — changes state, not deletion
- **UUID v7**: `Str::orderedUuid()` for primary keys — avoids InnoDB clustered index fragmentation
- **Filter queries**: Use Laravel's `when()` method, not if statements
- **Test naming**: `{method_name} {what it does}` (e.g., `store creates notification with valid data`)
- **PHPStan level 6**: All code must pass static analysis at level 6

### Status Lifecycle

`pending` → `queued` → `processing` → `delivered`
                                    ↘ `failed` → `retrying` → `delivered`
                                                             ↘ `permanently_failed`
`cancelled` (from `pending`, `queued`, or `retrying`)

### API Endpoints

- `POST   /api/notifications`              — create single notification
- `POST   /api/notifications/batch`        — create batch (up to 1000)
- `GET    /api/notifications/{id}`         — get status
- `GET    /api/notifications`              — list with filters + cursor-based pagination
- `PATCH  /api/notifications/{id}/cancel`  — cancel pending/queued/retrying
- `GET    /api/notifications/batch/{id}`   — batch status
- `GET    /api/health`                     — health check
- `GET    /api/metrics`                    — queue depth, rates, latency

### External Provider

```
POST https://webhook.site/{WEBHOOK_SITE_UUID}
Body: { "to": "+905551234567", "channel": "sms", "content": "Your message" }
Response 202: { "messageId": "uuid", "status": "accepted", "timestamp": "ISO8601" }
```

# Event-Driven Notification System

A scalable notification system built with Laravel 11 that processes and delivers messages through SMS, Email, and Push channels.

---

## Tech Stack

- **Framework**: PHP Laravel 11
- **Database**: MySQL 8
- **Queue / Cache**: Redis + Laravel Horizon
- **API Docs**: Swagger / OpenAPI (L5-Swagger)
- **Testing**: Pest 3
- **Code Quality**: Laravel Pint, PHPStan (Larastan level 6)
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

---

## API Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| `POST` | `/api/notifications` | Create single notification |
| `POST` | `/api/notifications/batch` | Create batch (up to 1000) |
| `GET` | `/api/notifications` | List with filters + cursor pagination |
| `GET` | `/api/notifications/{id}` | Get notification by ID |
| `GET` | `/api/notifications/batch/{batchId}` | Get batch status summary |
| `PATCH` | `/api/notifications/{id}/cancel` | Cancel a notification |

---

## Design Decisions

**Cancellation scope** — Extended beyond just `pending` to include `queued` and `retrying` statuses. Notifications that haven't been delivered yet should be cancellable. `processing`, `delivered`, `failed`, and `permanently_failed` cannot be cancelled.

**Cursor-based pagination** — Used instead of offset-based because `OFFSET N` degrades at scale. Cursor pagination is consistently fast regardless of dataset size.

**Separate batch endpoint** — `POST /api/notifications/batch` instead of detecting array/single in the store method. Cleaner REST design, easier to test and document.

**PATCH for cancel** — Cancel changes state, it doesn't delete the resource. `PATCH /api/notifications/{id}/cancel` is semantically correct.

**Correlation ID from middleware** — Generated once per request in `CorrelationIdMiddleware`, shared across all notifications in the same request (important for batch). Enables distributed tracing.

**Idempotency key** — Client-provided key to prevent duplicate notifications on network retries. Duplicate request returns `200` with existing notification instead of creating a new one.

**Batch chunking** — Batch inserts are chunked into groups of 100 to avoid exceeding database packet limits and memory pressure on large batches (up to 1000).

**Separate batch controller** — `BatchNotificationController` handles batch store and batch status, keeping `NotificationController` focused on single notification CRUD. Single Responsibility Principle.

**Event-driven processing** — `NotificationCreated` event fires on creation, `QueueNotificationListener` sets status to `queued` and dispatches `SendNotificationJob` to the appropriate priority queue. Decouples API response from async processing.

**Priority queues** — Three Redis queues (`high`, `normal`, `low`) managed by Laravel Horizon. Workers process in priority order. Notification priority maps directly to queue name.

**Atomic status claim** — `SendNotificationJob` uses `UPDATE ... WHERE status = 'queued'` to atomically claim a notification. If affected rows = 0, another worker already claimed it — prevents duplicate processing.

**Rate limiting** — 100 messages/second/channel using Redis `INCR` + `EXPIRE` sliding window. Each channel is tracked independently. When the limit is exceeded, the job is released back to the queue with a 1-second delay.

**UUID v7 (ordered)** — Used `Str::orderedUuid()` for primary keys to avoid InnoDB clustered index fragmentation with random UUIDs.

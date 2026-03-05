# Event-Driven Notification System

A scalable notification system built with Laravel 11 that processes and delivers messages through SMS, Email, and Push channels. Handles high throughput, reliable delivery with retries, and real-time status tracking.

## Quick Start

1. **Clone** the repository
2. **Set your webhook UUID** in `docker-compose.yml` (see [Webhook.site Configuration](#webhooksite-configuration))
3. **Start everything**:
   ```bash
   docker-compose up -d
   ```
4. **Done** — API is live at http://localhost:8080

No `.env` file needed — all configuration lives in `docker-compose.yml`.

---

## Services & Tools

| Service | URL | Purpose |
|---------|-----|---------|
| **App API** | http://localhost:8080 | REST API for notifications |
| **Horizon Dashboard** | http://localhost:8080/horizon | Monitor queues, workers, failed jobs |
| **Adminer** (MySQL GUI) | http://localhost:8081 | Server: `mysql`, User: `laravel`, Pass: `secret`, DB: `notification_db` |
| **Redis Commander** | http://localhost:8082 | Inspect Redis keys, queue data, rate limiter counters |
| **Swagger API Docs** | http://localhost:8080/api/documentation | Interactive API docs (run `php artisan l5-swagger:generate` first) |

---

## Webhook.site Configuration

The system uses [webhook.site](https://webhook.site) to simulate external SMS/Email/Push delivery providers.

1. Go to https://webhook.site and copy your **UUID** from the URL
2. Set it in `docker-compose.yml`:
   ```yaml
   WEBHOOK_SITE_UUID: "your-uuid-here"
   ```
3. Configure the webhook.site response (click the **Edit** button on the top right):
   - **Status Code**: `202`
   - **Content-Type**: `application/json`
   - **Body**:
     ```json
     {
       "messageId": "f47ac10b-58cc-4372-a567-0e02b2c3d479",
       "status": "accepted",
       "timestamp": "2026-03-04T12:00:00Z"
     }
     ```

---

## Architecture Overview

```
Client → REST API → NotificationCreated Event → QueueNotificationListener
  → Priority Queue (high/normal/low) → Horizon Worker → SendNotificationJob
  → Rate Limiter Check → Circuit Breaker Check → Atomic Status Claim
  → Channel Provider (SMS/Email/Push) → POST to webhook.site
  → Status Update (delivered/retrying/permanently_failed)
```

1. **API receives request** — validates, creates notification with `pending` status
2. **Event fires** — `NotificationCreated` triggers `QueueNotificationListener`
3. **Listener dispatches job** — sets status to `queued`, dispatches `SendNotificationJob` to the appropriate priority queue
4. **Worker picks up job** — Horizon manages workers across `high`, `normal`, `low` queues
5. **Rate limiter checks** — 100 messages/second/channel via Redis sliding window; if exceeded, job is released back with 1s delay
6. **Circuit breaker checks** — if channel has too many recent failures, job is released back with 30s delay
7. **Atomic claim** — `UPDATE ... WHERE status IN ('queued', 'retrying')` prevents duplicate processing by competing workers
8. **Provider delivers** — `ChannelProviderFactory` resolves the correct provider (SMS/Email/Push), POSTs to webhook.site
9. **Status updated** — `delivered` on success, `retrying` with exponential backoff on retryable failure, `permanently_failed` on non-retryable or max attempts exhausted

---

## API Endpoints

### Create Notification

```bash
curl -X POST http://localhost:8080/api/notifications \
  -H "Content-Type: application/json" \
  -d '{
    "recipient": "+905551234567",
    "channel": "sms",
    "content": "Hello from the notification system!",
    "priority": "high",
    "idempotency_key": "unique-key-123"
  }'
```

- `channel`: `sms`, `email`, `push`
- `priority`: `high`, `normal` (default), `low`
- `idempotency_key`: optional, prevents duplicate sends on retry
- Content limits: SMS 160 chars, Email 10,000 chars, Push 500 chars

### Create Batch

```bash
curl -X POST http://localhost:8080/api/notifications/batch \
  -H "Content-Type: application/json" \
  -d '{
    "notifications": [
      {"recipient": "+905551234567", "channel": "sms", "content": "Hello 1"},
      {"recipient": "user@example.com", "channel": "email", "content": "Hello 2"}
    ]
  }'
```

Up to 1,000 notifications per batch.

### Get Notification

```bash
curl http://localhost:8080/api/notifications/{id}
```

### List Notifications

```bash
curl "http://localhost:8080/api/notifications?status=delivered&channel=sms&per_page=20"
```

Filters: `status`, `channel`, `date_from`, `date_to`, `per_page` (1-100), `cursor`.

### Cancel Notification

```bash
curl -X PATCH http://localhost:8080/api/notifications/{id}/cancel
```

Cancellable statuses: `pending`, `queued`, `retrying`.

### Batch Status

```bash
curl http://localhost:8080/api/notifications/batch/{batchId}
```

Returns total count and per-status breakdown.

---

## Tech Stack

- **Framework**: PHP Laravel 11
- **Database**: MySQL 8
- **Queue / Cache / Rate Limiting**: Redis + Laravel Horizon
- **API Docs**: Swagger / OpenAPI (L5-Swagger)
- **Testing**: Pest 3 (TDD)
- **Code Quality**: Laravel Pint, PHPStan (Larastan level 6)
- **Container**: Docker Compose

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

**Strategy pattern for channel providers** — `NotificationChannelInterface` defines the contract. `AbstractChannelProvider` handles common HTTP logic (POST to webhook.site, response parsing, timeout handling). `SmsProvider`, `EmailProvider`, `PushProvider` extend it and override `formatPayload()`. `ChannelProviderFactory` resolves the correct provider by `Channel` enum. Non-retryable errors (4xx) vs retryable errors (5xx, timeouts) are distinguished in the `DeliveryResult` DTO.

**UUID v7 (ordered)** — Used `Str::orderedUuid()` for primary keys to avoid InnoDB clustered index fragmentation with random UUIDs.

**Channel content limits** — SMS: 160 chars, Email: 10,000 chars, Push: 500 chars. Enforced at the validation layer for both single and batch endpoints.

**Isolated worker pools per priority** — Instead of a single worker group processing all queues in order, each priority level has its own dedicated worker pool (high: 3 processes, normal: 2, low: 1). This prevents low-priority bulk notifications from blocking high-priority messages. In a single-pool setup, a worker processing a low-priority job can't pick up a new high-priority job until it finishes.

**Retry with exponential backoff and jitter** — Failed deliveries retry up to `max_attempts` (default 3) with exponential backoff: `base_delay * 2^(attempt-1) + random(0, base_delay)`. With the default 30s base delay: attempt 1 waits 30-60s, attempt 2 waits 60-90s, attempt 3 waits 120-150s. Jitter is proportional to the base delay to spread retries and prevent thundering herd. Non-retryable errors (4xx) skip retries entirely and go straight to `permanently_failed`. Uses Laravel's `$job->release($delay)` to re-queue with the calculated delay.

**Circuit breaker** — Per-channel circuit breaker using Redis prevents overwhelming a failing provider. Uses a count-based approach (not percentage-based) for simplicity — tracks raw failure counts in a sliding time window. Three states: **closed** (normal, all requests pass), **open** (too many failures, requests rejected immediately), **half-open** (after cooldown, one probe request allowed). Config: 5 failures within 60s trips open, 30s cooldown before half-open. When circuit is open, jobs are released back to the queue with a 30s delay. On successful delivery the circuit resets to closed; on failure during half-open the circuit reopens with a fresh cooldown.

**Horizontal scaling** — The Horizon service can be scaled independently with `docker compose up --scale horizon=N`. Each instance manages its own worker pool. The API and queue processing are already separate containers sharing the same codebase but with different entry points (`php-fpm` vs `php artisan horizon`), making horizontal scaling straightforward without code changes.

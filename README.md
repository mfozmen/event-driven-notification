![CI](https://github.com/mfozmen/event-driven-notification/actions/workflows/ci.yml/badge.svg)
[![Quality Gate Status](https://sonarcloud.io/api/project_badges/measure?project=mfozmen_event-driven-notification&metric=alert_status)](https://sonarcloud.io/summary/new_code?id=mfozmen_event-driven-notification)

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
| **Scheduler** | — | Runs `notifications:process-stuck` and `notifications:process-scheduled` every minute |
| **Reverb** (WebSocket) | ws://localhost:8085 | Real-time notification status updates via WebSocket |

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
  → NotificationLogger records each transition to notification_logs table
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
10. **Retry flow** — Provider fails → `RetryStrategy` checks if retryable → if yes and attempts remaining: status → `retrying`, `release($delay)` with exponential backoff + jitter → worker picks up again after delay. If `release($delay)` fails silently or worker crashes mid-retry, the safety net command re-queues stuck notifications.
11. **Safety net** — `notifications:process-stuck` runs every minute via Laravel scheduler. Finds notifications stuck in `retrying` with a past `next_retry_at`, sets status to `queued`, and re-dispatches to the correct priority queue.

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

### Health Check

```bash
curl http://localhost:8080/api/health
```

Returns `200` with `status: "healthy"` when all services (database, Redis, Horizon) are up. Returns `503` with `status: "degraded"` if any service is down. No `X-Correlation-ID` middleware — designed for load balancers and monitoring tools.

### Metrics

```bash
curl http://localhost:8080/api/metrics
```

Returns queue depths (high/normal/low), delivery counts (success/failure per channel), average latency per channel, and total notification counts by status.

### Notification Trace

```bash
curl http://localhost:8080/api/notifications/{id}/trace
```

Returns an ordered list of status transition log entries for a notification: `created → queued → processing → delivered` (or `retrying → permanently_failed`). Each entry includes `event`, `correlation_id`, `details`, and `created_at`.

### Scheduled Notifications

Send a notification at a specific time by including `scheduled_at`:

```bash
curl -X POST http://localhost:8080/api/notifications \
  -H "Content-Type: application/json" \
  -d '{
    "recipient": "+905551234567",
    "channel": "sms",
    "content": "Reminder: your appointment is tomorrow",
    "scheduled_at": "2026-03-10T09:00:00Z"
  }'
```

The notification stays in `pending` status until `scheduled_at` arrives. The `notifications:process-scheduled` command (runs every minute) picks up due notifications, sets status to `queued`, and dispatches them to the priority queue. Past `scheduled_at` values are queued immediately. Omitting `scheduled_at` queues immediately (existing behavior).

### Create Template

```bash
curl -X POST http://localhost:8080/api/templates \
  -H "Content-Type: application/json" \
  -d '{
    "name": "welcome-sms",
    "channel": "sms",
    "body_template": "Hello {{name}}, welcome to {{company}}!",
    "variables": ["name", "company"]
  }'
```

### List Templates

```bash
curl http://localhost:8080/api/templates
```

### Get Template

```bash
curl http://localhost:8080/api/templates/{id}
```

### Update Template

```bash
curl -X PUT http://localhost:8080/api/templates/{id} \
  -H "Content-Type: application/json" \
  -d '{
    "name": "welcome-sms-v2",
    "channel": "sms",
    "body_template": "Hi {{name}}, thanks for joining {{company}}!",
    "variables": ["name", "company"]
  }'
```

### Delete Template

```bash
curl -X DELETE http://localhost:8080/api/templates/{id}
```

Returns `204` on success. Returns `409` if the template is referenced by existing notifications.

### Create Notification from Template

Instead of providing `content` directly, reference a template:

```bash
curl -X POST http://localhost:8080/api/notifications \
  -H "Content-Type: application/json" \
  -d '{
    "recipient": "+905551234567",
    "channel": "sms",
    "template_id": "template-uuid-here",
    "template_variables": {"name": "Alice", "company": "Acme Corp"}
  }'
```

The `content` field is rendered from the template: `"Hello Alice, welcome to Acme Corp!"`. When `template_id` is provided, `content` is optional — the template generates it. If both are provided, the template takes precedence.

### WebSocket Real-Time Updates

Subscribe to notification status changes in real-time via WebSocket (Laravel Reverb).

**Connection:**
```
ws://localhost:8085/app/notification-key
```

**Channel:** `notifications.{notificationId}` (public)

**Event:** `notification.status.updated`

**Payload:**
```json
{
  "id": "019538a1-7b2c-7e3a-9f4d-1a2b3c4d5e6f",
  "status": "delivered",
  "attempts": 1,
  "updated_at": "2026-03-05T12:00:00+00:00"
}
```

Events are broadcast on every status transition: `queued`, `delivered`, `retrying`, `permanently_failed`, `cancelled`.

**How to subscribe** (using any Pusher-compatible client):

1. Connect to `ws://localhost:8085/app/notification-key`
2. Subscribe to channel `notifications.{notificationId}` where `{notificationId}` is the UUID returned when creating the notification
3. Listen for the `notification.status.updated` event
4. The payload includes the current `status`, `attempts` count, and `updated_at` timestamp

Works with [Laravel Echo](https://laravel.com/docs/broadcasting#client-side-installation), [Pusher JS](https://github.com/pusher/pusher-js), `wscat`, or any WebSocket client that speaks the Pusher protocol.

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

### Code Quality Metrics

- **251 tests**, 914 assertions
- **PHPStan Level 6**: 0 errors
- **PHP Insights**: Code 93.3%, Complexity 94.9%, Architecture 81.3%, Style 92.7%
- **0 known security vulnerabilities** (`composer audit`)

---

## Artisan Commands

```bash
# Re-queue stuck retrying notifications (runs automatically every minute via scheduler service)
php artisan notifications:process-stuck

# Queue scheduled notifications whose time has arrived (runs automatically every minute via scheduler service)
php artisan notifications:process-scheduled
```

---

## Scaling Considerations

The current architecture handles moderate scale well. At millions of notifications daily, these are the next steps:

**CQRS (Command Query Responsibility Segregation)** — Separate read and write models. Writes go to MySQL, reads served from Elasticsearch or Redis-cached denormalized views. The current single-table approach works at moderate scale with proper indexing and cursor-based pagination, but read-heavy dashboards and filtering would benefit from a dedicated read store.

**Database partitioning** — Partition the `notifications` table by `created_at` (monthly). Old partitions can be archived or dropped without affecting query performance on recent data. Combined with UUID v7 ordered keys, this keeps InnoDB indexes compact per partition.

**Message broker** — Replace Redis queues with Kafka or RabbitMQ for guaranteed delivery, replay capability, and higher throughput. Redis queues work well for current volume but lack persistence guarantees and consumer group semantics needed at scale.

**Read replicas** — Route list/filter queries to MySQL read replicas, keeping the primary for writes only. Laravel's `DB::connection('read')` makes this straightforward with minimal code changes.

**Separate microservices** — Split channel providers (SMS, Email, Push) into independent services that can be deployed and scaled independently per channel. Each service consumes from its own queue topic, allowing independent scaling based on channel-specific load patterns.

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

**Safety net command** — `notifications:process-stuck` runs every minute via Laravel scheduler. Catches notifications stuck in `retrying` status where `next_retry_at` has passed — sets status back to `queued` and re-dispatches `SendNotificationJob` to the correct priority queue. This handles edge cases where `$job->release($delay)` fails silently or a worker crashes mid-retry. Processes in chunks of 100 to handle large backlogs without memory issues.

**Horizontal scaling** — The Horizon service can be scaled independently with `docker compose up --scale horizon=N`. Each instance manages its own worker pool. The API and queue processing are already separate containers sharing the same codebase but with different entry points (`php-fpm` vs `php artisan horizon`), making horizontal scaling straightforward without code changes.

**Health check endpoint** — `GET /api/health` checks database, Redis, and Horizon connectivity with latency measurements. Returns `200 healthy` or `503 degraded`. Excludes `CorrelationIdMiddleware` so load balancers and monitoring tools can call it without generating correlation IDs.

**Metrics endpoint** — `GET /api/metrics` returns queue depths (Redis LLEN), delivery success/failure counts per channel (Redis INCR counters with 1-hour TTL), average latency per channel (last 100 deliveries), and total notification counts by status (database aggregation). Counters are recorded in `SendNotificationJob` after each delivery attempt.

**Structured JSON logging** — Custom `JsonLogFormatter` implements Monolog's `FormatterInterface`. Outputs one JSON object per line with `timestamp`, `level`, `message`, and optional `correlation_id`, `notification_id`, `channel` from context. Configured as the `json` log channel in `config/logging.php`, set as default via `LOG_CHANNEL=json` in `docker-compose.yml`.

**Distributed tracing** — `NotificationLogger` service records every status transition to the `notification_logs` table: `created`, `queued`, `processing`, `delivered`, `retrying`, `permanently_failed`, `cancelled`. Each log entry includes `notification_id`, `correlation_id`, `event`, optional `details` (JSON), and `created_at`. The `GET /api/notifications/{id}/trace` endpoint returns these entries in chronological order, providing a complete audit trail for each notification.

**Scheduled notifications** — Notifications with a future `scheduled_at` stay in `pending` status. The `QueueNotificationListener` checks `scheduled_at` and skips dispatch if it's in the future. The `notifications:process-scheduled` command runs every minute, picks up pending notifications where `scheduled_at <= now()`, and dispatches them to the correct priority queue. Past `scheduled_at` values are queued immediately on creation. The `notifications:process-stuck` command has a `whereNull('scheduled_at')` guard so it doesn't interfere with scheduled pending notifications.

**Template system** — `NotificationTemplate` model with `render(array $variables): string` method performs `{{variable}}` substitution. Templates are managed via full CRUD at `/api/templates`. When creating a notification with `template_id`, the service resolves the template, renders content with provided `template_variables`, and stores the rendered string in the `content` field. Missing variables return `422`. If both `template_id` and `content` are provided, the template takes precedence. Templates referenced by notifications cannot be deleted (returns `409`). The foreign key uses `nullOnDelete` as a safety net — the rendered content is already stored in the notification.

**Swagger/OpenAPI annotations** — All endpoints annotated using PHP 8 attributes (`OpenApi\Attributes`). Four tag groups organize the API: Notifications, Batch, Templates, Observability. The `POST /api/notifications` annotation reflects optional `content` (when using templates), `scheduled_at`, `template_id`, and `template_variables` fields. Interactive docs available at `/api/documentation`.

**WebSocket real-time updates** — `NotificationStatusUpdated` event implements `ShouldBroadcast`, broadcasting on a public `notifications.{notificationId}` channel via Laravel Reverb. Fired on every status transition: `queued` (from listener), `delivered`, `retrying`, `permanently_failed` (from job), and `cancelled` (from service). Payload includes `id`, `status`, `attempts`, and `updated_at`. Uses public channels for simplicity — any Pusher-compatible WebSocket client can subscribe. In production, use private channels with authentication to restrict access to authorized clients only. Reverb runs as a separate Docker service on port 8085.

**Inbound API rate limiting** — API routes are protected with Laravel's `ThrottleRequests` middleware at 60 requests per minute per IP. This is separate from the outbound channel rate limiting (100 messages/second/channel) — inbound protects the API from abuse, outbound protects external providers from overload.

**GitHub Actions CI** — Automated pipeline runs on push and PR to `main`. Steps: Pint code style check, PHPStan level 6 static analysis, full Pest test suite. Uses SQLite in-memory (same as local testing) — no MySQL/Redis services needed in CI. PHP 8.4 with `shivammathur/setup-php`.

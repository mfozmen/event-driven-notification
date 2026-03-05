# Implementation Plan v2 — Event-Driven Notification System

## Context

Building a scalable event-driven notification system for the Insider One software engineer assessment. The system processes and delivers messages through SMS, Email, and Push channels with high throughput, reliable delivery, retry logic, and real-time status tracking. All bonus features included. TDD approach with Pest PHP. Greenfield Laravel 11+ project.

### Key Architecture Decisions

- **MySQL 8** — Aligns with the company's existing stack. UUID v7 (`Str::orderedUuid()`) for primary keys to avoid InnoDB clustered index fragmentation.
- **Redis** — Queue broker (not data store). Holds job references (notification IDs), rate limiting counters, and circuit breaker state. MySQL is the single source of truth.
- **Laravel Horizon** — Worker management, priority queue routing, and built-in monitoring dashboard.
- **Two-layer validation** — Basic validation at the API layer (FormRequest), post-processing validation in jobs (e.g., after template rendering).
- **Separate batch endpoint** — `POST /api/notifications` for single, `POST /api/notifications/batch` for batch. Clean REST design.
- **Retry via `release($delay)`** — Primary retry uses Laravel's native job release with calculated delay. A scheduled safety-net command catches stuck jobs.

### Redis-DB Relationship

Redis and MySQL serve different roles — they are NOT mirrors of each other:

- **MySQL** = source of truth (all notification data lives here)
- **Redis** = transient job queue (carries notification IDs, not data)

Flow: API → MySQL write (status: pending) → Dispatch job to Redis (just the ID) → Worker reads from Redis → Fetches notification from MySQL → Sends → Updates MySQL status.

Race condition prevention: Worker sets `status = processing` via atomic UPDATE:
```sql
UPDATE notifications SET status = 'processing' WHERE id = :id AND status = 'queued'
```
If affected rows = 0, another worker already claimed it → skip.

If Redis crashes, a recovery command re-queues notifications stuck in `queued` status.

---

## Current Progress

- ✅ Phase 1 — Docker Compose (app, nginx, mysql, redis, adminer)
- ✅ Phase 2 — Migration, model, enums, factory, 5 passing tests
- ✅ Phase 3a — Create & read single notification
- ✅ Phase 3b — List notifications with filtering and cursor pagination
- ✅ Phase 3c — Cancel notification
- ✅ Phase 3d — Batch create & batch status
- 🔲 Phase 4a — Starting now

---

## Phase 3a — Create & Read Single Notification (TDD)

**Goal**: `POST /api/notifications` (single) and `GET /api/notifications/{id}`.

Shared infrastructure built here (reused in all later phases):
- `NotificationController`
- `NotificationResource`
- `NotificationService`
- `CorrelationIdMiddleware`

### Tests first:
- `POST /api/notifications` — successful creation, returns notification with correct structure
- `POST /api/notifications` — validation errors (missing recipient, invalid channel, invalid priority, content too long)
- `POST /api/notifications` — idempotency key dedup (same key returns existing notification, no duplicate created)
- `POST /api/notifications` — SMS content validation (max 160 chars at API layer)
- `GET /api/notifications/{id}` — found, returns correct resource shape
- `GET /api/notifications/{id}` — not found (404)

### Implementation:
1. `CorrelationIdMiddleware` — generates/propagates `X-Correlation-ID` header
2. `StoreNotificationRequest` — validates single payload including channel-specific content limits
3. `NotificationService::create()` — persists notification, fires `NotificationCreated` event (event listener stubbed for now, will be wired in Phase 4)
4. `NotificationResource` — shapes JSON response
5. `NotificationController::store()` and `show()`
6. Routes in `routes/api.php`

**Commit**: "feat: create and read single notification with validation and idempotency"

---

## Phase 3b — List Notifications (TDD)

**Goal**: `GET /api/notifications` with filters and cursor-based pagination.

### Tests first:
- Returns paginated list with correct structure
- Filter by `status`
- Filter by `channel`
- Filter by `date_from` and `date_to`
- Multiple filters combined
- Cursor-based pagination works correctly (no offset)

### Implementation:
1. `ListNotificationsRequest` — validates filter params
2. `NotificationController::index()` — cursor-based pagination (`WHERE id < :last_id ORDER BY id DESC LIMIT :per_page`)

**Note**: Cursor-based pagination instead of offset-based. At scale, `OFFSET 50000` degrades badly. Cursor pagination is consistently fast regardless of dataset size.

**Commit**: "feat: list notifications with filtering and cursor-based pagination"

---

## Phase 3c — Cancel Notification (TDD)

**Goal**: `PATCH /api/notifications/{id}/cancel` (PATCH, not DELETE — we're changing state, not removing a resource).

### Tests first:
- Cancel a `pending` notification → status becomes `cancelled`
- Cancel a `queued` notification → status becomes `cancelled`
- Reject cancel if status is `processing`, `delivered`, `failed`, or `permanently_failed` (409 Conflict)
- Not found (404)

### Implementation:
1. `NotificationService::cancel()`
2. `NotificationController::cancel()`

**Commit**: "feat: cancel pending/queued notifications"

---

## Phase 3d — Batch Create & Batch Status (TDD)

**Goal**: `POST /api/notifications/batch` (separate endpoint) and `GET /api/notifications/batch/{batchId}`.

### Tests first:
- Batch create — array of up to 1000 notifications, returns `batch_id` + count summary
- Batch create — exceeds 1000 limit (422)
- Batch create — validation error in one item rejects entire batch
- Batch create — bulk insert uses chunking (100 per chunk), not individual inserts
- `GET /api/notifications/batch/{batchId}` — returns total, per-status counts
- `GET /api/notifications/batch/{batchId}` — not found (404)

### Implementation:
1. `StoreBatchNotificationRequest` — validates array payload, max 1000 items, each item validated with same rules as single
2. `NotificationService::createBatch()` — chunks into groups of 100, bulk INSERT per chunk, single shared `batch_id`, dispatches jobs in chunks (not 1000 individual dispatches)
3. `NotificationController::storeBatch()` — dedicated method for batch endpoint
4. `NotificationController::batchStatus()`

**Commit**: "feat: batch create and batch status endpoints"

---

## Phase 4a — Horizon Setup & Event/Listener/Job Chain (TDD)

**Goal**: Infrastructure only. Notification creation triggers async processing with correct priority routing. The job does NOT call channel providers — it only handles status transitions and queue mechanics. Provider integration is added in Phase 5.

### Why split Phase 4:
Phase 4 was too large — Horizon setup, event/listener/job chain, and rate limiting all at once makes debugging hard if something breaks. Splitting lets us verify each layer independently.

### Tests first:
- Unit: `SendNotificationJob` performs atomic status transition (`queued` → `processing`)
- Unit: Idempotency — job skips if notification status is not `queued`
- Feature: Creating a notification dispatches `SendNotificationJob`
- Feature: High priority notification is dispatched to `high` queue
- Feature: Normal priority notification goes to `normal` queue

### Implementation:

1. **Install & configure Laravel Horizon**
   - `composer require laravel/horizon`
   - Configure environments, supervisors, and queue priorities in `config/horizon.php`
   - Add `horizon` service to `docker-compose.yml`: `php artisan horizon`

2. **Event → Listener → Job chain**:
   - `NotificationCreated` event
   - `QueueNotificationListener` — sets status to `queued`, dispatches `SendNotificationJob` to appropriate priority queue
   - `SendNotificationJob` — atomic status claim (`queued` → `processing`), but does NOT call the channel provider yet. Just updates status. Provider integration comes in Phase 5.
   - Idempotency check in job — skip if notification is not in `queued` status

3. **Queue Configuration**:
   - Three queues: `high`, `normal`, `low`
   - Horizon workers process `--queue=high,normal,low` (priority ordering)

**Commit**: "feat: Horizon setup with event-driven job dispatching and priority queues"

---

## Phase 4b — Rate Limiter (TDD)

**Goal**: Channel-based rate limiting using Redis sliding window counter.

### Tests first:
- Unit: `ChannelRateLimiter` allows requests under limit
- Unit: `ChannelRateLimiter` blocks when limit exceeded
- Unit: Counter resets after window expires
- Feature: Rate-limited job is released back to queue

### Implementation:

1. **`ChannelRateLimiter`** service — Redis sliding window (`INCR` + `EXPIRE`)
   - Key pattern: `rate_limit:{channel}:{current_second}`, TTL: 2 seconds
   - Threshold: 100 messages per second per channel
2. **Integrate into `SendNotificationJob`** — check rate limit before processing, if exceeded call `$this->release(1)` to retry after 1 second

**Commit**: "feat: Redis sliding window rate limiter per channel"

---

## Phase 5 — Channel Providers & Delivery (TDD)

**Goal**: Strategy pattern for channel delivery, all hitting webhook.site. Wire providers into the existing `SendNotificationJob`.

### Tests first:
- Unit: `NotificationChannelInterface` contract
- Unit: `SmsProvider`, `EmailProvider`, `PushProvider` — request format, response parsing
- Unit: `ChannelProviderFactory` resolves correct provider by channel enum
- Integration: HTTP mock — successful delivery (202), failed delivery (500), timeout

### Implementation:

1. **Interface**: `NotificationChannelInterface`
   ```php
   public function send(Notification $notification): DeliveryResult;
   ```
2. **Providers**: `SmsProvider`, `EmailProvider`, `PushProvider`
   - All POST to `https://webhook.site/{WEBHOOK_SITE_UUID}`
   - Payload: `{ "to": recipient, "channel": channel, "content": content }`
   - Parse 202 response → `DeliveryResult` with external messageId
3. **DTO**: `DeliveryResult` — success/failure, external messageId, error message
4. **Factory**: `ChannelProviderFactory` — resolves provider by `Channel` enum
5. **Config**: `config/notifications.php` — webhook URL, per-channel settings
6. **Wire into `SendNotificationJob`** — after atomic status claim and rate limit check, call the resolved provider's `send()` method. Update status to `delivered` on success, `failed` on failure.

**Commit**: "feat: channel providers with strategy pattern and webhook.site integration"

---

## Phase 6a — RetryStrategy + Job Retry Integration (TDD)

**Goal**: Add retry logic with exponential backoff and jitter. Replace immediate `failed` status with `retrying` (retryable errors) or `permanently_failed` (non-retryable or max attempts exceeded).

### Design decisions:
- **Max retries**: 3 attempts (configurable via `max_attempts` column)
- **Backoff**: Exponential with jitter — `base_delay * 2^(attempt-1) + random(0, base_delay)`
  - Attempt 1: 30-60s, Attempt 2: 60-90s, Attempt 3: 120-150s (with default 30s base delay)
  - Jitter is proportional to base delay, prevents thundering herd when many notifications fail simultaneously
- **Retryable failures**: HTTP 5xx, timeouts, connection errors
- **Non-retryable**: HTTP 4xx (bad request → `permanently_failed` immediately)
- **Primary retry mechanism**: `$this->release($calculatedDelay)` — Laravel's native job release

### Tests first:
- Unit: `RetryStrategy::calculateDelay()` — exponential backoff with base delay
- Unit: `RetryStrategy::calculateDelay()` — adds jitter up to 1000ms
- Unit: `RetryStrategy::shouldRetry()` — true when retryable and attempts remaining
- Unit: `RetryStrategy::shouldRetry()` — false when non-retryable or max attempts reached
- Unit: Job retries retryable failure when attempts remaining (status → retrying)
- Unit: Job sets permanently_failed when max attempts reached
- Unit: Job sets permanently_failed for non-retryable failure
- Feature: Retryable failure sets status to retrying and re-queues
- Feature: Non-retryable failure sets permanently_failed immediately
- Feature: Full retry cycle — attempt 1 fails (retrying), attempt 2 fails (retrying), attempt 3 fails (permanently_failed)

### Implementation:
1. **`RetryStrategy`** service — `shouldRetry(DeliveryResult, attempts, maxAttempts)` and `calculateDelay(attempt)`
2. **Update `SendNotificationJob`** — add RetryStrategy as 3rd DI parameter, replace failure path with retry/permanently_failed logic, update `claimNotification()` to accept both `queued` and `retrying` statuses

**Commit**: "feat: retry strategy with exponential backoff and jitter"

---

## Phase 6b — Circuit Breaker (TDD)

**Goal**: Redis-backed per-channel circuit breaker to pause delivery when a channel is experiencing high failure rates.

### Design decisions:
- **Per-channel**: Each channel (SMS/Email/Push) has its own circuit breaker
- **Threshold**: >50% failure rate in last 60s sliding window, minimum 10 requests
- **States**: Closed (normal) → Open (blocking) → Half-open (testing)
- **Cooldown**: 30s before transitioning from open to half-open
- **Redis-backed**: Sliding window counters stored in Redis

### Tests first:
- Unit: Circuit breaker stays closed under normal failure rates
- Unit: Circuit breaker opens after exceeding threshold
- Unit: Circuit breaker transitions to half-open after cooldown
- Unit: Half-open allows one request through
- Feature: Job releases back to queue when circuit is open

### Implementation:
1. **`CircuitBreaker`** service — Redis sliding window, per-channel state tracking
2. **Integrate into `SendNotificationJob`** — check circuit breaker before calling provider, if open → release job

**Commit**: "feat: per-channel circuit breaker with Redis sliding window"

---

## Phase 6c — Safety Net Command (TDD)

**Goal**: Artisan command to catch notifications stuck in `retrying` status and re-dispatch them.

### Design decisions:
- **Query**: `status = retrying AND next_retry_at <= now()`
- **Runs**: Every minute via Laravel scheduler
- **Batch processing**: Chunks of 100 to avoid memory issues

### Tests first:
- Unit: Command finds stuck retrying notifications
- Unit: Command re-dispatches stuck notifications to correct priority queue
- Unit: Command ignores retrying notifications with future next_retry_at
- Feature: Full flow — notification gets stuck, command re-dispatches, notification gets delivered

### Implementation:
1. **`ProcessStuckNotifications`** artisan command — queries stuck notifications, sets status back to `queued`, dispatches `SendNotificationJob`
2. **Schedule**: `$schedule->command('notifications:process-stuck')->everyMinute()` in `routes/console.php`

**Commit**: "feat: safety net command for stuck retrying notifications"

---

## Phase 7 — Observability (TDD)

**Goal**: Metrics, structured logging, health checks, distributed tracing.

### Tests first:
- Feature: `GET /api/health` — returns status of app, db, redis, queue
- Feature: `GET /api/metrics` — returns queue depths, success/failure rates, latency
- Unit: Structured log format includes correlation_id, channel, notification_id
- Feature: `GET /api/notifications/{id}/trace` — returns status transition log

### Implementation:

1. **Health check**: `HealthController` — pings DB, Redis, checks Horizon status
2. **Metrics**: `MetricsController`
   - Queue depths per priority (from Horizon API or Redis `LLEN`)
   - Success/failure counts (last 1m, 5m, 1h) — Redis counters incremented on delivery result
   - Average delivery latency per channel
3. **Structured logging**: Custom JSON log formatter, correlation_id propagated from middleware through jobs
4. **Distributed tracing**: `notification_logs` table
   - `id`, `notification_id`, `correlation_id`, `event` (e.g., created, queued, processing, delivered, failed), `details` (JSON), `created_at`
   - `NotificationLogger` service — logs every status transition
   - `GET /api/notifications/{id}/trace` endpoint — returns ordered log entries

**Commit**: "feat: health check, metrics, structured logging, and distributed tracing"

---

## Phase 8 — Bonus: Scheduled Notifications & Template System (TDD)

### Scheduled Notifications

`scheduled_at` column already exists in migration.

#### Tests first:
- Create notification with `scheduled_at` in future → stays `pending`, NOT immediately queued
- Scheduler picks up due notifications and queues them
- Notification with `scheduled_at` in the past is treated as immediate

#### Implementation:
- Modify `QueueNotificationListener` — if `scheduled_at` is in the future, skip dispatch
- `ProcessScheduledNotifications` artisan command — queries `status = pending AND scheduled_at <= now AND scheduled_at IS NOT NULL`, dispatches to queue
- Runs every minute via Laravel scheduler
- Add `scheduler` service to `docker-compose.yml`

### Template System

#### Migration:
`notification_templates` table:
- `id` (UUID, primary)
- `name` (string, unique)
- `channel` (enum: sms, email, push)
- `body_template` (text) — uses `{{variable}}` syntax
- `variables` (JSON — list of expected variable names)
- `created_at`, `updated_at`

Add to `notifications` table:
- `template_id` (UUID, nullable, FK)
- `template_variables` (JSON, nullable)

#### Tests first:
- Unit: Template rendering with variable substitution
- Unit: Missing variable throws validation error
- Feature: Create notification with `template_id` and `template_variables` → content rendered from template
- Feature: Template CRUD — create, read, update, delete

#### Implementation:
- `NotificationTemplate` model with `render(array $variables): string`
- Template CRUD: `POST/GET/PUT/DELETE /api/templates`
- On notification creation, if `template_id` provided, render content from template + variables, store rendered content in `content` field

**Commit**: "feat: scheduled notifications and template system"

---

## Phase 9 — Bonus: WebSocket Real-Time Updates (TDD)

**Goal**: Real-time notification status updates via WebSocket.

### Tests first:
- Unit: `NotificationStatusUpdated` event is broadcast on status change
- Feature: WebSocket channel authentication

### Implementation:
1. **Laravel Reverb** — first-party WebSocket server
2. **Broadcast event**: `NotificationStatusUpdated` fires on every status transition
3. **Channel**: `notifications.{id}` — private channel
4. Add `reverb` service to `docker-compose.yml`

**Note**: If Reverb causes Docker issues, skip this and document it as a "would implement" in README with architecture diagram. Don't let this block delivery.

**Commit**: "feat: WebSocket real-time status updates via Reverb"

---

## Phase 10 — API Documentation & README

1. **Swagger/OpenAPI**: Add annotations to all controllers and form requests
2. **README.md**:
   - Architecture overview with diagram (Mermaid)
   - Setup instructions (`docker-compose up` one-liner)
   - API examples (curl commands for every endpoint)
   - Design decisions section (UUID v7 choice, cursor pagination, retry strategy, circuit breaker rationale)
   - What I'd do differently at true scale (partitioning, Kafka, separate read replicas)
3. Generate and verify Swagger UI at `/api/documentation`

**Commit**: "docs: Swagger/OpenAPI annotations and comprehensive README"

---

## Phase 11 — GitHub Actions CI/CD (Bonus)

1. `.github/workflows/ci.yml`:
   - Trigger: push/PR to main
   - Services: MySQL, Redis
   - Steps: `composer install` → `pint --test` → `phpstan analyse` → `php artisan test`
2. Badge in README

**Commit**: "ci: GitHub Actions pipeline with tests, lint, and static analysis"

---

## Verification Checklist

After each phase:
1. `php artisan test` — all tests pass
2. `./vendor/bin/pint --test` — code style clean
3. `./vendor/bin/phpstan analyse` — no static analysis errors

Final end-to-end:
1. `docker-compose down -v && docker-compose up -d` — clean start
2. Run migrations
3. POST single notification → verify queued → verify delivered via webhook.site
4. POST batch of 100 → verify all processed
5. Cancel a pending notification
6. Check `GET /api/health` and `GET /api/metrics`
7. Configure webhook.site to return 500 → verify retry attempts → eventually `permanently_failed`
8. Create notification with future `scheduled_at` → run scheduler → verify delivery
9. Create template → create notification with template_id → verify rendered content
10. Verify Swagger UI at `/api/documentation`
11. Full test suite passes: `php artisan test`

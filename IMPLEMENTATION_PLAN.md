# Implementation Plan — Event-Driven Notification System

## Context

Building a scalable event-driven notification system for the Insider One software engineer assessment. The system processes and delivers messages through SMS, Email, and Push channels with high throughput, reliable delivery, retry logic, and real-time status tracking. All bonus features included. TDD approach with Pest PHP. Greenfield Laravel 11+ project.

---

## Phase 1 — Project Scaffolding & Docker Setup

**Goal**: One-command `docker-compose up` that boots Laravel with all services.

1. Initialize Laravel 11 project via `composer create-project`
2. Create `docker-compose.yml` with services:
   - **app**: PHP 8.3-FPM with required extensions (pdo_mysql, redis, pcntl, sockets)
   - **nginx**: Reverse proxy for app
   - **mysql**: MySQL 8, persistent volume
   - **redis**: For queues, rate limiting, cache, WebSocket
3. Create `Dockerfile` for PHP app container
4. Create `docker/nginx/default.conf`
5. Configure `.env` with:
   - `DB_*` for MySQL container
   - `QUEUE_CONNECTION=redis`
   - `CACHE_STORE=redis`
   - `WEBHOOK_SITE_UUID=your-uuid-here` (placeholder)
6. Install core dependencies:
   - `pestphp/pest` + `pestphp/pest-plugin-laravel` (testing)
   - `darkaonline/l5-swagger` (API docs)
   - `laravel/pint` (code style)
   - `phpstan/phpstan` + `larastan/larastan` (static analysis)
   - `laravel/reverb` (WebSocket)
7. Configure Pest, PHPStan, Pint config files
8. **Commit**: "chore: scaffold Laravel project with Docker Compose"

---

## Phase 2 — Database Schema & Models (TDD)

**Goal**: `notifications` table and `Notification` model with enums. Only what the core requirements need.

### Tests first:

- Unit test: Notification model — factory, enums, fillable, casts

### Migration:

`notifications` table:
- `id` (UUID, primary)
- `batch_id` (UUID, nullable, indexed)
- `idempotency_key` (string, nullable, unique)
- `correlation_id` (UUID)
- `recipient` (string) — phone/email/device token
- `channel` (enum: sms, email, push)
- `content` (text)
- `priority` (enum: high, normal, low, default: normal)
- `status` (enum: pending, queued, processing, delivered, failed, retrying, permanently_failed, cancelled)
- `attempts` (int, default: 0)
- `max_attempts` (int, default: 3)
- `next_retry_at` (timestamp, nullable)
- `last_attempted_at` (timestamp, nullable)
- `delivered_at` (timestamp, nullable)
- `failed_at` (timestamp, nullable)
- `scheduled_at` (timestamp, nullable — bonus: scheduled notifications)
- `error_message` (text, nullable)
- `created_at`, `updated_at`
- Indexes: `status`, `channel`, `priority`, `batch_id`

### Models:

- `Notification` with backed enums: `Channel`, `Priority`, `Status`
- `NotificationFactory`

**Commit**: "feat: notifications table, model, and enums"

---

## Phase 3 — Notification Management API (TDD)

**Goal**: Full CRUD API with validation, filtering, pagination.

### Tests first (Pest Feature tests):

- `POST /api/notifications` — single creation with all fields
- `POST /api/notifications` — batch creation (array of notifications, up to 1000)
- `POST /api/notifications` — validation errors (missing fields, invalid channel, etc.)
- `POST /api/notifications` — idempotency key dedup
- `GET /api/notifications/{id}` — found, not found
- `GET /api/notifications` — list with filters (status, channel, date_from, date_to) + pagination
- `GET /api/notifications/batch/{batchId}` — batch status summary
- `DELETE /api/notifications/{id}` — cancel pending, reject if already processing/delivered
- `POST /api/notifications` — with scheduled_at in the future (bonus, requires Phase 8)

### Implementation:

1. **Routes**: `routes/api.php`
2. **Controller**: `NotificationController` (resourceful)
   - `store()` — handles both single and batch (detects array)
   - `show()` — by ID
   - `index()` — filtered list
   - `destroy()` — cancel
   - `batchStatus()` — batch summary
3. **Form Requests**:
   - `StoreNotificationRequest` — validates single + batch payloads
   - `ListNotificationRequest` — validates filter params
4. **Resources**:
   - `NotificationResource` / `NotificationCollection` — JSON:API response formatting
5. **Service**:
   - `NotificationService` — business logic layer (create, cancel, batch create)
   - Dispatches `NotificationCreated` event on creation
6. **Middleware**:
   - `CorrelationIdMiddleware` — generates/propagates `X-Correlation-ID` header

**Commit**: "feat: notification management API with full CRUD and batch support"

---

## Phase 4 — Processing Engine (TDD)

**Goal**: Async queue processing with priorities, rate limiting, idempotency.

### Tests first:

- Unit test: `SendNotificationJob` — dispatches to correct channel provider
- Unit test: Rate limiter — blocks when exceeding 100/s per channel
- Unit test: Idempotency check — skips duplicate idempotency keys
- Unit test: Content validation — channel-specific limits (SMS: 160 chars, Push: title + body)
- Feature test: Job dispatched on notification creation
- Feature test: Priority queue routing (high → high queue, etc.)

### Implementation:

1. **Event**: `NotificationCreated` → **Listener**: `QueueNotificationListener`
   - Sets status to `queued`, dispatches `SendNotificationJob` to priority queue
2. **Job**: `SendNotificationJob`
   - Picks correct channel provider via `ChannelProviderFactory`
   - Checks idempotency
   - Validates content
   - Checks rate limit (Redis token bucket via `RateLimiter`)
   - Calls provider `send()` method
   - Updates status throughout lifecycle
3. **Rate Limiter**: `ChannelRateLimiter`
   - Redis-based, 100 tokens/second per channel
   - If rate limited, release job back to queue with short delay
4. **Queue Configuration**:
   - `config/queue.php` — define `high`, `normal`, `low` queues
   - Workers process `--queue=high,normal,low` (priority ordering)
5. **Content Validator**: `NotificationContentValidator`
   - SMS: max 160 chars
   - Push: title required, body max 500 chars

**Commit**: "feat: async processing engine with priority queues, rate limiting, and idempotency"

---

## Phase 5 — Channel Providers & Delivery (TDD)

**Goal**: Strategy pattern for channel delivery, all hitting webhook.site.

### Tests first:

- Unit test: `NotificationChannelInterface` contract
- Unit test: `SmsProvider`, `EmailProvider`, `PushProvider` — send method, request format, response parsing
- Unit test: Provider factory resolves correct provider by channel
- Integration test: HTTP mock — successful delivery (202), failed delivery (500), timeout

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

**Commit**: "feat: channel providers with strategy pattern and webhook.site integration"

---

## Phase 6 — Retry Logic (TDD) — Candidate-Designed

**Goal**: Intelligent retry with exponential backoff and circuit breaking.

### Design decisions:

- **Max retries**: 3 attempts (configurable)
- **Backoff**: Exponential with jitter — `base_delay * 2^attempt + random(0, 1000ms)`
  - Attempt 1: ~30s, Attempt 2: ~120s, Attempt 3: ~480s
- **Retryable failures**: HTTP 5xx, timeouts, connection errors
- **Non-retryable**: HTTP 4xx (bad request — permanent failure immediately)
- **Dead letter**: After max retries → `permanently_failed` status, logged
- **Circuit breaker** (per channel): If >50% failure rate in last 60s, pause channel briefly

### Tests first:

- Unit test: Retry delay calculation (exponential backoff with jitter)
- Unit test: Retryable vs non-retryable error classification
- Unit test: Max retries reached → permanently_failed
- Unit test: Circuit breaker opens/closes based on failure rate
- Feature test: Full retry flow — fail → retry → succeed
- Feature test: Full retry flow — fail → max retries → permanently_failed

### Implementation:

1. **`RetryStrategy`**: Calculates delay, determines retryability
2. **`CircuitBreaker`**: Redis-backed, per-channel failure tracking
3. **Job `failed()` method**: Handles retry scheduling or permanent failure
4. **Scheduler command**: `ProcessRetryableNotifications` — picks up notifications with `next_retry_at <= now` and re-dispatches

**Commit**: "feat: exponential backoff retry logic with circuit breaker"

---

## Phase 7 — Observability (TDD)

**Goal**: Metrics, structured logging, health checks, distributed tracing.

### Tests first:

- Feature test: `GET /api/health` — returns service status (app, db, redis, queue)
- Feature test: `GET /api/metrics` — returns queue depths, rates, latency
- Unit test: Correlation ID middleware generates/propagates UUID
- Unit test: Structured log format includes correlation_id, channel, notification_id
### Implementation:

1. **Health check**: `HealthController` — pings DB, Redis, checks queue worker
2. **Metrics**: `MetricsController`
   - Queue depths per priority (via Redis `LLEN`)
   - Success/failure counts (last 1m, 5m, 1h) — Redis counters
   - Average delivery latency — tracked per channel
3. **Correlation ID middleware**: Already added in Phase 3, ensure propagation to jobs
4. **Structured logging**: Custom log formatter (JSON), includes correlation_id

**Bonus — Distributed Tracing**:
- Migration: `notification_logs` table (`id`, `notification_id`, `correlation_id`, `event`, `details` JSON, `created_at`)
- `NotificationLog` model
- Log every status transition
- `GET /api/notifications/{id}/trace` endpoint

**Commit**: "feat: observability — health check, metrics, structured logging, tracing"

---

## Phase 8 — Bonus: Scheduled Notifications & Template System (TDD)

### Scheduled Notifications

`scheduled_at` column already exists on `notifications` table (added in Phase 2).

#### Tests first:
- Feature test: Create notification with `scheduled_at` in future → stays `pending`, not immediately queued
- Feature test: Scheduler picks up due notifications and queues them

#### Implementation:
- `ProcessScheduledNotifications` artisan command — queries `status=pending AND scheduled_at <= now`, dispatches to queue
- Runs every minute via `schedule:run`
- Add `scheduler` service to `docker-compose.yml`

---

### Template System

#### Migration:
`notification_templates` table:
- `id` (UUID, primary)
- `name` (string, unique)
- `channel` (enum: sms, email, push)
- `body_template` (text)
- `variables` (JSON — list of expected variable names)
- `created_at`, `updated_at`

Add to `notifications` table:
- `template_id` (UUID, nullable, FK → notification_templates)
- `template_variables` (JSON, nullable)

#### Tests first:
- Unit test: Template rendering with variable substitution
- Feature test: Create notification with `template_id` → content rendered from template
- Feature test: CRUD for templates

#### Implementation:
- `NotificationTemplate` model with `render(array $variables): string`
- Template CRUD API: `POST/GET/PUT/DELETE /api/templates`
- On notification creation, if `template_id` provided, render content from template + variables

**Commit**: "feat: scheduled notifications and template system"

---

## Phase 9 — Bonus: WebSocket Real-Time Updates (TDD)

**Goal**: Real-time notification status updates via WebSocket.

### Tests first:

- Unit test: `NotificationStatusUpdated` event is broadcast on status change
- Feature test: WebSocket channel authentication

### Implementation:

1. Use **Laravel Reverb** (Laravel's first-party WebSocket server)
2. **Broadcast event**: `NotificationStatusUpdated` on every status change
3. **Channel**: `notifications.{id}` — private channel
4. Add Reverb server to `docker-compose.yml`

**Commit**: "feat: WebSocket real-time status updates"

---

## Phase 10 — API Documentation & README

1. **Swagger/OpenAPI**: Add annotations to all controllers and form requests
2. **README.md**: Architecture overview, setup instructions, API examples, design decisions
3. Generate and verify Swagger UI works at `/api/documentation`

**Commit**: "docs: Swagger/OpenAPI annotations and README"

---

## Phase 11 — GitHub Actions CI/CD (Bonus)

1. `.github/workflows/ci.yml`:
   - Run on push/PR to main
   - Services: MySQL, Redis
   - Steps: composer install, pint check, phpstan, pest tests
2. Badge in README

**Commit**: "ci: GitHub Actions pipeline with tests, lint, and static analysis"

---

## Verification

After each phase:

1. Run `php artisan test` — all tests pass
2. Run `./vendor/bin/pint --test` — code style check
3. Run `./vendor/bin/phpstan analyse` — no static analysis errors

Final end-to-end verification:

1. `docker-compose down -v && docker-compose up -d` — clean start
2. Run migrations, seed test data
3. POST a batch of notifications → verify queued → verify delivered via webhook.site
4. Check metrics endpoint, health endpoint
5. Cancel a pending notification
6. Test retry by configuring webhook.site to return 500 → verify retry attempts → eventually permanently_failed
7. Test scheduled notification — create with future `scheduled_at`, run scheduler, verify delivery
8. Verify Swagger UI at `/api/documentation`
9. Full test suite passes: `php artisan test`

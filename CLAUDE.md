# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

Event-Driven Notification System for Insider One — a scalable system that processes and delivers messages through multiple channels (SMS, Email, Push). Handles high throughput, reliable delivery with retries, and real-time status tracking.

## Tech Stack

- **Framework**: PHP Laravel 11+ (latest)
- **Queue**: Redis-backed Laravel queues with priority support (high, normal, low)
- **Database**: MySQL 8
- **Cache/Rate Limiting**: Redis
- **Containerization**: Docker Compose (one-command setup)
- **API Docs**: Swagger/OpenAPI via L5-Swagger
- **Testing**: PHPUnit + Pest (TDD approach)
- **External Provider**: webhook.site (simulates SMS/Email/Push delivery)

## Development Approach

**Test-Driven Development (TDD)** — write tests first, then implement.

## Commands

```bash
# Setup
docker-compose up -d
docker-compose exec app composer install
docker-compose exec app php artisan migrate

# Tests
docker-compose exec app php artisan test                    # run all tests
docker-compose exec app php artisan test --filter=TestName  # run single test
docker-compose exec app php artisan test --testsuite=Feature
docker-compose exec app php artisan test --testsuite=Unit

# Queue workers
docker-compose exec app php artisan queue:work --queue=high,normal,low

# Linting
docker-compose exec app ./vendor/bin/pint                   # Laravel Pint (code style)
docker-compose exec app ./vendor/bin/phpstan analyse        # static analysis

# API docs
docker-compose exec app php artisan l5-swagger:generate
```

## Architecture

### Core Domain

- **Notification**: Central entity — recipient, channel (sms/email/push), content, priority (high/normal/low), status lifecycle
- **Batch**: Groups up to 1000 notifications per request, tracked by batch_id
- **Channel Providers**: Strategy pattern — each channel (SMS, Email, Push) has a provider class implementing a common interface, all POST to webhook.site

### Key Patterns

- **Event-Driven**: Notification creation fires events → listeners dispatch to queues
- **Strategy Pattern**: Channel-specific delivery logic behind a `NotificationChannelInterface`
- **Rate Limiting**: 100 messages/second/channel via Redis token bucket
- **Idempotency**: Client-provided idempotency key stored and checked to prevent duplicate sends
- **Retry with Backoff**: Exponential backoff for failed deliveries (candidate-designed)
- **Correlation IDs**: UUID attached to every request, propagated through queue jobs and logs

### API Endpoints (RESTful)

- `POST   /api/notifications`       — create single or batch
- `GET    /api/notifications/{id}`   — get status by ID
- `GET    /api/notifications`        — list with filters (status, channel, date range) + pagination
- `DELETE /api/notifications/{id}`   — cancel pending notification
- `GET    /api/notifications/batch/{batchId}` — get batch status
- `GET    /api/health`               — health check
- `GET    /api/metrics`              — real-time metrics (queue depth, success/failure rates, latency)

### Notification Status Lifecycle

`pending` → `queued` → `processing` → `delivered` | `failed` → `retrying` → `delivered` | `permanently_failed`

Also: `cancelled` (from pending/queued)

### External Provider Integration

All channels POST to webhook.site:
```
POST https://webhook.site/{uuid}
Body: { "to": "+905551234567", "channel": "sms", "content": "Your message" }
Response (202): { "messageId": "uuid", "status": "accepted", "timestamp": "ISO8601" }
```

## Deliverables

1. Source code with clean commit history
2. README.md with setup instructions, architecture, API examples
3. Docker Compose one-command setup
4. Swagger/OpenAPI documentation
5. Database migrations
6. Test suite (single command)

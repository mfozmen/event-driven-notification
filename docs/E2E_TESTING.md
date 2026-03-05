# End-to-End Testing Checklist

Manual testing checklist for verifying the full notification system.

---

## Prerequisites

```bash
# Clean start
docker compose down -v && docker compose up -d

# Wait for all services to be healthy
docker compose ps

# Verify migrations ran (entrypoint handles this automatically)
docker compose exec app php artisan migrate --force
```

---

## Automated Checks

```bash
# All tests must pass
docker compose exec app php artisan test

# Code style
docker compose exec app php vendor/bin/pint --test

# Static analysis
docker compose exec app php vendor/bin/phpstan analyse
```

---

## Configure webhook.site for Delivery

By default, webhook.site returns `200 OK` with an HTML page. The channel providers expect a `202` response with JSON. Without this configuration, all deliveries will fail.

1. Go to [webhook.site](https://webhook.site) — a unique URL is generated automatically
2. Copy the UUID from the URL (e.g., `https://webhook.site/#!/view/abc-123-def` → UUID is `abc-123-def`)
3. Update `WEBHOOK_SITE_UUID` in `docker-compose.yml` with your UUID
4. On the webhook.site page, click **Edit** (top right)
5. Set **Status Code** to `202`
6. Set **Content Type** to `application/json`
7. Set **Response Body** to:
   ```json
   {"messageId": "test-msg-001", "status": "accepted", "timestamp": "2026-03-04T00:00:00Z"}
   ```
8. Click **Save**
9. Restart services to pick up the new UUID:
   ```bash
   docker compose down && docker compose up -d
   ```

---

## API Flow Tests

### 1. Create Single Notification

```bash
curl -s -w "\nHTTP_STATUS: %{http_code}" -X POST http://localhost:8080/api/notifications \
  -H "Content-Type: application/json" \
  -d '{"recipient": "+905551234567", "channel": "sms", "content": "Hello from test"}'
```

**Expected:** HTTP 201, response contains `id`, `status` is `queued` (transitions to `delivered` after Horizon processes it).

### 2. Get Notification by ID

```bash
curl -s http://localhost:8080/api/notifications/{id}
```

**Expected:** HTTP 200, `status` is `delivered` (if webhook.site is configured), `delivered_at` is set, `attempts` is 1.

### 3. Create Batch

```bash
curl -s -w "\nHTTP_STATUS: %{http_code}" -X POST http://localhost:8080/api/notifications/batch \
  -H "Content-Type: application/json" \
  -d '{"notifications": [{"recipient": "+905551234567", "channel": "sms", "content": "Batch 1"}, {"recipient": "user@test.com", "channel": "email", "content": "Batch 2"}]}'
```

**Expected:** HTTP 201, response contains `batch_id` and `count: 2`.

### 4. Get Batch Status

```bash
curl -s http://localhost:8080/api/notifications/batch/{batch_id}
```

**Expected:** HTTP 200, `total: 2`, `status_counts` shows `delivered: 2` (after processing).

### 5. List with Filters

```bash
curl -s "http://localhost:8080/api/notifications?status=delivered&per_page=5"
```

**Expected:** HTTP 200, returns `data` array with delivered notifications, `meta` with `per_page` and `next_cursor`.

### 6. Cancel a Notification

Create a notification, then cancel it before Horizon picks it up. Using `scheduled_at` in the future keeps it in a cancellable state longer:

```bash
# Create
curl -s -w "\nHTTP_STATUS: %{http_code}" -X POST http://localhost:8080/api/notifications \
  -H "Content-Type: application/json" \
  -d '{"recipient": "+905551234567", "channel": "sms", "content": "Cancel me"}'

# Cancel immediately (use the ID from the create response)
curl -s -w "\nHTTP_STATUS: %{http_code}" -X PATCH http://localhost:8080/api/notifications/{id}/cancel
```

**Expected:** Cancel returns HTTP 200, notification `status` is `cancelled`. If Horizon already claimed it (status is `processing`), cancel returns HTTP 409.

### 7. Idempotency Test

```bash
# First request
curl -s -w "\nHTTP_STATUS: %{http_code}" -X POST http://localhost:8080/api/notifications \
  -H "Content-Type: application/json" \
  -d '{"recipient": "+905551234567", "channel": "sms", "content": "Idempotency test", "idempotency_key": "my-unique-key"}'

# Duplicate request (same idempotency_key)
curl -s -w "\nHTTP_STATUS: %{http_code}" -X POST http://localhost:8080/api/notifications \
  -H "Content-Type: application/json" \
  -d '{"recipient": "+905551234567", "channel": "sms", "content": "Idempotency test", "idempotency_key": "my-unique-key"}'
```

**Expected:** First returns HTTP 201 (created). Second returns HTTP 200 (existing) with the same `id`.

### 8. Verify X-Correlation-ID Header

```bash
curl -s -D - -o /dev/null -X POST http://localhost:8080/api/notifications \
  -H "Content-Type: application/json" \
  -d '{"recipient": "+905551234567", "channel": "sms", "content": "Header test"}' \
  | grep -i correlation
```

**Expected:** Response includes `X-Correlation-ID: <uuid>` header.

### 9. Horizon Status

```bash
docker compose exec app php artisan horizon:status
```

**Expected:** `Horizon is running.`

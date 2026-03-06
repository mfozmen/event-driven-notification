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

## Services Health Check

After `docker compose up -d`, verify all services are accessible. If any of these fail after a clean `docker compose down -v && docker compose up -d`, it's a bug.

| Service | URL | Expected |
|---------|-----|----------|
| **Swagger UI** | http://localhost:8080/api/documentation | Loads with all endpoints documented under 4 tags: Notifications, Batch, Templates, Observability |
| **Horizon Dashboard** | http://localhost:8080/horizon | Loads, shows 3 supervisors: `notification-worker-high`, `notification-worker-normal`, `notification-worker-low` |
| **Adminer** | http://localhost:8081 | Loads login page. Connect with: Server `mysql`, User `laravel`, Password `secret`, Database `notification_db` |
| **Redis Commander** | http://localhost:8082 | Loads, shows Redis keys (queues, horizon data, rate limiter counters) |
| **Reverb** (WebSocket) | ws://localhost:8085 | Real-time notification status updates via WebSocket |

Swagger UI at http://localhost:8080/api/documentation must render without errors — no "Unable to render this definition" or "The provided definition does not specify a valid version field" messages. All endpoints should be visible and expandable. Verify by checking that the page shows the API title "Event-Driven Notification API" and lists all 6 operations (GET/POST/PATCH across notifications and batch).

```bash
# Quick verification (all should return HTTP 200)
curl -s -o /dev/null -w "Swagger:          %{http_code}\n" http://localhost:8080/api/documentation
curl -s -o /dev/null -w "Horizon:          %{http_code}\n" http://localhost:8080/horizon
curl -s -o /dev/null -w "Adminer:          %{http_code}\n" http://localhost:8081
curl -s -o /dev/null -w "Redis Commander:  %{http_code}\n" http://localhost:8082

# Verify Swagger spec is valid OpenAPI 3.0 with all endpoints
curl -s http://localhost:8080/docs/api-docs.json | grep -o '"openapi":"3.0.0"'
curl -s http://localhost:8080/docs/api-docs.json | grep -c '/api/notifications'  # should be 5 (paths)
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

---

## Retry Logic Tests

These tests verify the retry mechanism with exponential backoff. They require changing webhook.site responses between steps.

### 10. Retryable Failure → Retry → Deliver

1. On webhook.site, click **Edit** and set **Status Code** to `500`. Click **Save**.
2. Create a notification:
   ```bash
   curl -s -w "\nHTTP_STATUS: %{http_code}" -X POST http://localhost:8080/api/notifications \
     -H "Content-Type: application/json" \
     -d '{"recipient": "+905551234567", "channel": "sms", "content": "Retry test"}'
   ```
3. Wait 5 seconds, then check the notification status:
   ```bash
   curl -s http://localhost:8080/api/notifications/{id}
   ```
   **Expected:** `status` is `retrying`, `attempts` is `1`, `next_retry_at` is set, `error_message` contains the failure reason.

4. On webhook.site, click **Edit** and change **Status Code** back to `202`. Click **Save**.
5. Wait for the retry delay (~30-60s for the first retry), then check again:
   ```bash
   curl -s http://localhost:8080/api/notifications/{id}
   ```
   **Expected:** `status` is `delivered`, `attempts` is `2`, `delivered_at` is set.

### 11. Non-Retryable Failure → Permanently Failed

1. On webhook.site, click **Edit** and set **Status Code** to `400`. Click **Save**.
2. Create a notification:
   ```bash
   curl -s -w "\nHTTP_STATUS: %{http_code}" -X POST http://localhost:8080/api/notifications \
     -H "Content-Type: application/json" \
     -d '{"recipient": "+905551234567", "channel": "sms", "content": "Non-retryable test"}'
   ```
3. Wait 5 seconds, then check:
   ```bash
   curl -s http://localhost:8080/api/notifications/{id}
   ```
   **Expected:** `status` is `permanently_failed`, `attempts` is `1`, `failed_at` is set. No retry happens — 4xx errors are non-retryable.

### 12. Max Attempts Exhaustion

1. On webhook.site, click **Edit** and set **Status Code** to `500`. Keep it at 500 for the entire test.
2. Create a notification (default `max_attempts` is 3):
   ```bash
   curl -s -w "\nHTTP_STATUS: %{http_code}" -X POST http://localhost:8080/api/notifications \
     -H "Content-Type: application/json" \
     -d '{"recipient": "+905551234567", "channel": "sms", "content": "Max attempts test"}'
   ```
3. Poll the notification status over time. The retry cycle with exponential backoff (base delay 30s + jitter):
   - **After ~5s:** `status` is `retrying`, `attempts` is `1`
   - **After ~1 min:** `status` is `retrying`, `attempts` is `2` (retry 1 delay: 30-60s)
   - **After ~2-3 min:** `status` is `permanently_failed`, `attempts` is `3` (retry 2 delay: 60-90s)
   ```bash
   # Poll every 30 seconds
   curl -s http://localhost:8080/api/notifications/{id} | grep -o '"status":"[^"]*","attempts":[0-9]*'
   ```
   **Expected final state:** `status` is `permanently_failed`, `attempts` is `3`, `failed_at` is set, `error_message` is set.

4. Remember to set webhook.site back to `202` after testing.

### 13. Verify in Horizon Dashboard

After triggering retry failures, open http://localhost:8080/horizon:

- **Recent Jobs tab:** You should see completed jobs (delivered) and failed jobs (permanently_failed).
- **Retry timing:** Check job timestamps — the gap between attempts should roughly match the exponential backoff (30-60s, 60-90s).
- **Supervisors:** All 3 supervisors should be active: `notification-worker-high`, `notification-worker-normal`, `notification-worker-low`.

### 14. Verify in Redis Commander

Open http://localhost:8082 and inspect:

- **Rate limiter keys:** Look for keys matching `rate_limit:sms:*`, `rate_limit:email:*`, `rate_limit:push:*`. These appear during active sending and expire after 2 seconds.
- **Queue keys:** Look for `queues:high`, `queues:normal`, `queues:low` — these hold pending jobs.
- **Horizon keys:** Keys prefixed with `horizon:` contain supervisor state, job metrics, and worker data.

---

## Safety Net Command Tests

### 15. Manual Command Run (No Stuck Notifications)

```bash
docker compose exec app php artisan notifications:process-stuck
```

**Expected:** `Processed 0 stuck notifications.`

### 16. Scheduler Is Running

```bash
docker compose logs scheduler --tail=10
```

**Expected:** The scheduler service is running and executing the command every minute. You should see periodic output from `schedule:work`.

### 17. Stuck Notification Recovery

1. Create a notification stuck in `retrying` with a past `next_retry_at`:
   ```bash
   docker compose exec app php artisan tinker --execute="
     \App\Models\Notification::factory()->create([
       'status' => 'retrying',
       'next_retry_at' => now()->subMinutes(5),
       'attempts' => 1,
       'max_attempts' => 3,
       'recipient' => '+905551234567',
       'channel' => 'sms',
       'content' => 'Stuck test',
     ]);
   "
   ```
2. Run the safety net command:
   ```bash
   docker compose exec app php artisan notifications:process-stuck
   ```
   **Expected:** `Processed 1 stuck notifications.`
3. Check the notification status (use the ID from tinker output):
   ```bash
   curl -s http://localhost:8080/api/notifications/{id}
   ```
   **Expected:** `status` is `queued`, then eventually `delivered` after Horizon processes it (if webhook.site returns 202).

---

## Infinite Loop Prevention Tests (CRITICAL)

These tests verify that notifications cannot get stuck in an infinite retry loop.

### 18. Max Attempts Prevents Infinite Retry

1. On webhook.site, click **Edit** and set **Status Code** to `500`. Click **Save**.
2. Create a notification (default `max_attempts` is 3):
   ```bash
   curl -s -w "\nHTTP_STATUS: %{http_code}" -X POST http://localhost:8080/api/notifications \
     -H "Content-Type: application/json" \
     -d '{"recipient": "+905551234567", "channel": "sms", "content": "Infinite loop test"}'
   ```
3. Wait for all retry cycles to complete (~3-4 minutes with exponential backoff).
4. Verify via tinker:
   ```bash
   docker compose exec app php artisan tinker --execute="
     \App\Models\Notification::where('status', 'permanently_failed')->get(['id', 'attempts', 'max_attempts', 'status', 'error_message'])->toArray();
   "
   ```
   **Expected:** `status` is `permanently_failed`, `attempts` equals `max_attempts` (3). The notification must NOT be re-queued again after reaching `permanently_failed`.

### 19. Safety Net Respects permanently_failed

After test 18, run the safety net command:

```bash
docker compose exec app php artisan notifications:process-stuck
```

**Expected:** `Processed 0 stuck notifications.` — `permanently_failed` notifications are never re-queued.

### 20. Safety Net Respects Future next_retry_at

1. Create a notification with `retrying` status and a future `next_retry_at`:
   ```bash
   docker compose exec app php artisan tinker --execute="
     \App\Models\Notification::factory()->create([
       'status' => 'retrying',
       'next_retry_at' => now()->addMinutes(5),
       'attempts' => 1,
       'max_attempts' => 3,
       'recipient' => '+905551234567',
       'channel' => 'sms',
       'content' => 'Future retry test',
     ]);
   "
   ```
2. Run the command:
   ```bash
   docker compose exec app php artisan notifications:process-stuck
   ```
   **Expected:** `Processed 0 stuck notifications.` — the notification stays in `retrying` until its retry time arrives.

### 21. Circuit Breaker Prevents Hammering

1. On webhook.site, set **Status Code** to `500`.
2. Send several notifications rapidly:
   ```bash
   for i in $(seq 1 10); do
     curl -s -X POST http://localhost:8080/api/notifications \
       -H "Content-Type: application/json" \
       -d "{\"recipient\": \"+90555123456$i\", \"channel\": \"sms\", \"content\": \"Circuit breaker test $i\"}" &
   done
   wait
   ```
3. After 5+ failures, check Redis Commander at http://localhost:8082 for circuit breaker keys matching `circuit_breaker:sms:*`.
4. **Expected:** The circuit opens after 5 failures within 60 seconds. New jobs for the `sms` channel are released back to the queue with a 30s delay instead of hitting the provider. After the 30s cooldown, the circuit enters half-open and allows one probe request through.
5. Remember to set webhook.site back to `202` after testing.

---

## Observability Tests (Phase 7)

### 22. Health Check Endpoint

```bash
curl -s http://localhost:8080/api/health
```

**Expected:** HTTP 200, `status` is `healthy`, `services` contains `database`, `redis`, `horizon` each with `status: "up"` and `latency_ms`. No `X-Correlation-ID` header in the response (middleware excluded).

### 23. Metrics Endpoint

```bash
curl -s http://localhost:8080/api/metrics
```

**Expected:** HTTP 200, response contains `queue_depths` (with `high`, `normal`, `low`), `deliveries` (with per-channel `success`/`failure` counts), `latency` (with per-channel `avg_ms` and `sample_count`), `totals` (with counts by status), and `timestamp`.

### 24. Notification Trace (Full Lifecycle)

1. Create a notification and wait for delivery:
   ```bash
   RESPONSE=$(curl -s -X POST http://localhost:8080/api/notifications \
     -H "Content-Type: application/json" \
     -d '{"recipient": "+905551234567", "channel": "sms", "content": "Trace E2E test"}')
   ID=$(echo $RESPONSE | grep -o '"id":"[^"]*"' | head -1 | cut -d'"' -f4)
   echo "Notification ID: $ID"
   sleep 5
   ```
2. Fetch the trace:
   ```bash
   curl -s http://localhost:8080/api/notifications/$ID/trace
   ```
   **Expected:** HTTP 200, `data` array with entries in order: `created`, `queued`, `processing`, `delivered`. Each entry has `event`, `correlation_id`, `details`, and `created_at`.

### 25. Structured JSON Logs

```bash
docker compose logs app --tail=20
```

**Expected:** Log lines are JSON objects with `timestamp`, `level`, `message` fields. Entries related to notification processing should include `correlation_id` in context.

---

## Scheduled Notification Tests (Phase 8a)

### 26. Create Notification with Future scheduled_at

```bash
# Create notification scheduled 2 minutes in the future
SCHEDULED=$(date -u -d "+2 minutes" +"%Y-%m-%dT%H:%M:%SZ" 2>/dev/null || date -u -v+2M +"%Y-%m-%dT%H:%M:%SZ")
curl -s -w "\nHTTP_STATUS: %{http_code}" -X POST http://localhost:8080/api/notifications \
  -H "Content-Type: application/json" \
  -d "{\"recipient\": \"+905551234567\", \"channel\": \"sms\", \"content\": \"Scheduled test\", \"scheduled_at\": \"$SCHEDULED\"}"
```

**Expected:** HTTP 201, `status` is `pending` (NOT `queued`). The notification stays pending until its scheduled time arrives.

### 27. Process Scheduled Notifications

```bash
# Option 1: Wait 2 minutes for the scheduler to pick it up automatically
# Option 2: Run the command manually
docker compose exec app php artisan notifications:process-scheduled
```

**Expected:** `Processed 1 scheduled notifications.` Check the notification — `status` should now be `queued`, then `delivered` after Horizon processes it.

### 28. Create Notification with Past scheduled_at

```bash
curl -s -w "\nHTTP_STATUS: %{http_code}" -X POST http://localhost:8080/api/notifications \
  -H "Content-Type: application/json" \
  -d '{"recipient": "+905551234567", "channel": "sms", "content": "Past scheduled test", "scheduled_at": "2020-01-01T00:00:00Z"}'
```

**Expected:** HTTP 201, `status` is `queued` (immediately queued since `scheduled_at` is in the past). Eventually becomes `delivered`.

---

## Template System Tests (Phase 8b)

### 29. Create a Template

```bash
curl -s -w "\nHTTP_STATUS: %{http_code}" -X POST http://localhost:8080/api/templates \
  -H "Content-Type: application/json" \
  -d '{"name": "welcome-sms", "channel": "sms", "body_template": "Hello {{name}}, welcome to {{company}}!", "variables": ["name", "company"]}'
```

**Expected:** HTTP 201, response contains `id`, `name`, `channel`, `body_template`, and `variables`.

### 30. List Templates

```bash
curl -s http://localhost:8080/api/templates
```

**Expected:** HTTP 200, `data` array contains the `welcome-sms` template created in step 29.

### 31. Create Notification Using Template

```bash
# Use the template ID from step 29
curl -s -w "\nHTTP_STATUS: %{http_code}" -X POST http://localhost:8080/api/notifications \
  -H "Content-Type: application/json" \
  -d '{"recipient": "+905551234567", "channel": "sms", "template_id": "{TEMPLATE_ID}", "template_variables": {"name": "Alice", "company": "Acme Corp"}}'
```

**Expected:** HTTP 201, `content` is `"Hello Alice, welcome to Acme Corp!"` (rendered from template). No `content` field was sent in the request — it was generated from the template.

### 32. Create Notification with Missing Template Variable

```bash
curl -s -w "\nHTTP_STATUS: %{http_code}" -X POST http://localhost:8080/api/notifications \
  -H "Content-Type: application/json" \
  -d '{"recipient": "+905551234567", "channel": "sms", "template_id": "{TEMPLATE_ID}", "template_variables": {"name": "Alice"}}'
```

**Expected:** HTTP 422, validation error about missing required template variable `company`.

### 33. Delete Unreferenced Template

```bash
# Create a new template with no notifications referencing it
RESPONSE=$(curl -s -X POST http://localhost:8080/api/templates \
  -H "Content-Type: application/json" \
  -d '{"name": "temp-template", "channel": "email", "body_template": "Temp body", "variables": []}')
TEMP_ID=$(echo $RESPONSE | grep -o '"id":"[^"]*"' | head -1 | cut -d'"' -f4)

curl -s -w "\nHTTP_STATUS: %{http_code}" -X DELETE http://localhost:8080/api/templates/$TEMP_ID
```

**Expected:** HTTP 204 (no content). Template is deleted.

### 34. Delete Template Referenced by Notification

```bash
# Try to delete the welcome-sms template (referenced by the notification from step 31)
curl -s -w "\nHTTP_STATUS: %{http_code}" -X DELETE http://localhost:8080/api/templates/{TEMPLATE_ID}
```

**Expected:** HTTP 409 Conflict. Template cannot be deleted because it's referenced by existing notifications.

---

## WebSocket Verification (Phase 9)

> **Note:** WebSocket tests require manual browser interaction. Steps are documented for manual verification.

### 35. Verify Reverb Is Running

```bash
docker compose logs reverb --tail=5
```

**Expected:** Logs show `Starting server on 0.0.0.0:8085`.

```bash
docker compose ps reverb
```

**Expected:** Status is `running` (or `Up`).

```bash
# Verify Reverb is reachable (404 is expected — no route at /)
curl -s -o /dev/null -w "Reverb HTTP: %{http_code}\n" http://localhost:8085/
```

**Expected:** HTTP 404 (confirms the server is accepting connections).

### 36. WebSocket Test Client — Connection (Manual Verification)

1. Open `docs/websocket-test.html` in your browser (double-click or `file://` URL)
2. The status indicator should change from `disconnected` → `connecting` → `connected`
3. The log should show: `Connected to Reverb WebSocket server`

**If it stays disconnected:** Check that Reverb is running (`docker compose ps reverb`) and that port 8085 is mapped.

### 37. Full Delivery Flow via WebSocket (Manual Verification)

This verifies the complete event sequence: **queued → processing → delivered**.

1. Open `docs/websocket-test.html` in your browser, confirm `connected` status
2. Create a notification:
   ```bash
   curl -s -X POST http://localhost:8080/api/notifications \
     -H "Content-Type: application/json" \
     -d '{"recipient": "+905551234567", "channel": "sms", "content": "WebSocket delivery test"}'
   ```
3. Copy the `id` from the JSON response
4. Paste the ID into the test client input field and click **Subscribe**
5. The log should show: `Subscribed to notifications.{id} — waiting for events...`

Since the notification was already created before subscribing, you won't see past events. To see the full sequence, create a new notification AFTER subscribing:

6. Subscribe to a **new** notification ID first (you'll get the ID after creating):
   ```bash
   # Create notification and immediately subscribe
   RESPONSE=$(curl -s -X POST http://localhost:8080/api/notifications \
     -H "Content-Type: application/json" \
     -d '{"recipient": "+905551234567", "channel": "sms", "content": "WebSocket live test"}')
   echo $RESPONSE | grep -o '"id":"[^"]*"' | head -1
   ```
7. Quickly paste the ID into the test client and click **Subscribe**

**Expected events in the WebSocket client (in order):**
- `notification.status.updated` with `status: "processing"` (green-yellow)
- `notification.status.updated` with `status: "delivered"` (green)

> **Note:** The `queued` event fires at creation time. If you subscribe after creation, you'll miss it. The `processing` and `delivered` events fire during Horizon job execution, which typically happens within 1-5 seconds.

### 38. Cancel Flow via WebSocket (Manual Verification)

This verifies that cancellation broadcasts the `cancelled` event.

1. Open `docs/websocket-test.html`, confirm `connected`
2. Create a scheduled notification (far future so it stays in `pending`):
   ```bash
   curl -s -X POST http://localhost:8080/api/notifications \
     -H "Content-Type: application/json" \
     -d '{"recipient": "+905551234567", "channel": "sms", "content": "Cancel WS test", "scheduled_at": "2099-01-01T00:00:00Z"}'
   ```
3. Copy the notification ID from the response
4. Paste the ID into the test client and click **Subscribe**
5. Cancel the notification:
   ```bash
   curl -s -X PATCH http://localhost:8080/api/notifications/{id}/cancel
   ```

**Expected event in the WebSocket client:**
- `notification.status.updated` with `status: "cancelled"` (grey)

### 39. Retry Flow via WebSocket (Manual Verification)

This verifies that failed deliveries broadcast `retrying` events.

1. On webhook.site, click **Edit** and set **Status Code** to `500`. Click **Save**.
2. Open `docs/websocket-test.html`, confirm `connected`
3. Create a notification:
   ```bash
   curl -s -X POST http://localhost:8080/api/notifications \
     -H "Content-Type: application/json" \
     -d '{"recipient": "+905551234567", "channel": "sms", "content": "Retry WS test"}'
   ```
4. Quickly paste the notification ID and click **Subscribe**

**Expected events in the WebSocket client (in order):**
- `notification.status.updated` with `status: "processing"` (green-yellow)
- `notification.status.updated` with `status: "retrying"` (orange) with error message

5. On webhook.site, change **Status Code** back to `202`. Click **Save**.
6. Wait for the retry delay (~30-60s). More events should appear:
   - `notification.status.updated` with `status: "processing"` (second attempt)
   - `notification.status.updated` with `status: "delivered"` (green)

**Remember** to set webhook.site back to `202` after testing.

### Alternative: wscat (CLI)

```bash
# Install wscat (requires Node.js)
npm install -g wscat

# Connect to Reverb
wscat -c ws://localhost:8085/app/notification-key
```

Once connected, subscribe to a channel by sending:
```json
{"event":"pusher:subscribe","data":{"channel":"notifications.{notificationId}"}}
```

Then create or process a notification via curl — you should see `notification.status.updated` events arrive in real-time.

---

## Database Verification

### 40. Verify in Adminer

Open http://localhost:8081, connect with Server `mysql`, User `laravel`, Password `secret`, Database `notification_db`:

1. Run this query to inspect retry fields:
   ```sql
   SELECT id, status, attempts, max_attempts, next_retry_at, last_attempted_at, failed_at, error_message
   FROM notifications
   ORDER BY created_at DESC
   LIMIT 10;
   ```
2. **For retrying notifications:** `attempts` < `max_attempts`, `next_retry_at` is a future timestamp, `error_message` describes the failure.
3. **For permanently_failed notifications:** `attempts` = `max_attempts`, `failed_at` is set, `next_retry_at` may hold the last retry timestamp.
4. **For delivered notifications:** `attempts` ≥ 1, `delivered_at` is set, `failed_at` is null.

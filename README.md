# observera/laravel

Ship logs from any Laravel app to [Observera](../observera-api). Zero-config: once
installed with a key, every `Log::*` / `logger()` call is batched and shipped to
Observera's ingest endpoint. Non-blocking and fail-safe — a monitoring outage
never slows or breaks your app.

## Install

```bash
composer require observera/laravel

OR
composer config repositories.observera vcs https://github.com/Observra/observera-laravel-sdk
composer require observera/laravel:^1.0

php artisan observera:install
```

Then set in `.env`:

```dotenv
OBSERVERA_KEY=obs_live_…              # Observera → project → SDK keys
# OBSERVERA_ENDPOINT is optional — defaults to the hosted ingest endpoint.

# Recommended in production: ship off-thread via a queue instead of a blocking
# HTTP POST on every request. Set to a real queue connection (redis/database)
# and run a worker. Leave unset to ship inline (synchronous).
# OBSERVERA_QUEUE=redis
```

That's it. `Log::error('Payment failed', ['order' => $id])` now appears in
Observera under Logs, filterable by level / channel / search / trace id.

> **Performance.** By default the SDK ships **inline** on request/worker
> shutdown — the request waits on the network round-trip (up to `OBSERVERA_TIMEOUT`,
> 2s). On a slow or unreachable endpoint, or under Octane, this adds latency.
> Set `OBSERVERA_QUEUE=redis` (with a running queue worker) to ship
> asynchronously — the request returns immediately and delivery happens on the
> worker. The ship job is excluded from instrumentation, so it never loops.

Verify the connection (completes the onboarding "connection test"):

```bash
php artisan observera:test
```

Sends one event and reports success/failure with the HTTP status — use it to
debug key/endpoint issues.

## How it works

- Listens to Laravel's `MessageLogged` event — captures every channel, no
  `config/logging.php` changes.
- Buffers records and flushes in batches (`OBSERVERA_BATCH_SIZE`, default 50) and
  on request/command/job shutdown.
- Ships via Guzzle with short timeouts (`OBSERVERA_TIMEOUT`), swallowing all
  errors. `Throwable`s in context are reduced to `{class, message}` so the
  payload is always JSON-safe.
- Dormant when `OBSERVERA_KEY` is unset or `OBSERVERA_ENABLED=false`.

## Config

`config/observera.php` — `key`, `endpoint`, `environment`, `enabled`, `level`
(min level to ship), `batch_size`, `timeout`, `connect_timeout`.

## Payload

`POST {endpoint}/intake/events`, header `x-observera-key`, body:

```json
{ "logs": [ { "level": "error", "message": "...", "channel": "production",
             "context": {...}, "trace_id": "...", "timestamp": 1721470000000 } ] }
```

## Test

```bash
php tests/shipper_check.php   # framework-free self-check
```

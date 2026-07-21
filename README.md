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

# Shipping runs off-thread via a queue by default (connection "database") so it
# never blocks requests. Override to Redis, or "sync" to ship inline.
# OBSERVERA_QUEUE=redis
```

That's it. `Log::error('Payment failed', ['order' => $id])` now appears in
Observera under Logs, filterable by level / channel / search / trace id.

> **Performance.** Shipping is **async by default** — the envelope is dispatched
> to the queue (connection `database`) and delivered by a worker, so requests
> never wait on the network round-trip. Requirements: the `database` queue needs
> a `jobs` table (`php artisan queue:table && php artisan migrate`) and a running
> `php artisan queue:work`. Prefer Redis? Set `OBSERVERA_QUEUE=redis`. Want the
> old inline behaviour? Set `OBSERVERA_QUEUE=sync`. If the queue store is missing,
> the SDK safely falls back to inline delivery. The ship job is excluded from
> instrumentation, so it never loops.

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

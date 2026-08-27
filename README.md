# observera/laravel

Ship logs from any Laravel app to [Observera](../observera-api). Zero-config: once
installed with a key, every `Log::*` / `logger()` call is batched and shipped to
Observera's ingest endpoint. Non-blocking and fail-safe — a monitoring outage
never slows or breaks your app.

## Install

Not on Packagist — installed straight from the repo. Add it to your app's
`composer.json`:

```json
    "repositories": {
        "observera": {
            "type": "vcs",
            "url": "https://github.com/Observra/observera-laravel-sdk"
        }
    }
```

then track `main`:

```bash
composer require observera/laravel:dev-main
php artisan observera:install
```

`composer.lock` pins the exact commit, so installs stay reproducible; you move
forward only when you ask to:

```bash
composer update observera/laravel
```

Tracking a branch means there is no version gate — an update takes whatever
`main` holds. Read the commits between your locked sha and `main` before
updating a production app; `git log --oneline <locked-sha>..main` in this repo.

> The `v1.0.0`–`v1.1.1` tags all declare `"version": "1.0.0"` in their own
> `composer.json`, which Composer prefers over the tag name — so they are
> indistinguishable to Composer and constraints like `^1.0` cannot move between
> them. That field has been removed on `main`. Use `dev-main`, not the tags.

Then set in `.env`:

```dotenv
OBSERVERA_KEY=obs_live_…              # Observera → project → SDK keys
# OBSERVERA_ENDPOINT is optional — defaults to the hosted ingest endpoint.

# Shipping runs off-thread via a queue by default (connection "database") so it
# never blocks requests. Override to Redis, or "sync" to ship inline.
# OBSERVERA_QUEUE=redis

# Paths never shipped (health checks / probes). Laravel wildcards, comma-separated.
# Default: up,up/*,health,healthz,ping,telescope*,horizon*,_debugbar/*
# OBSERVERA_IGNORE_PATHS=up,health,metrics

# Max captured body size in bytes (outbound HTTP req/resp, job payloads). Larger
# bodies are truncated on a UTF-8 boundary. Default 0 = capture in full, because
# a truncated third-party response is usually the one you needed to read. Set a
# byte budget only if payload size becomes a real problem.
# OBSERVERA_MAX_BODY=0
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

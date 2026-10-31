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

# Shipping is inline by default, AFTER the response is sent, behind a circuit
# breaker. Recommended for busy apps / Octane: ship async via Redis.
# OBSERVERA_QUEUE=redis

# Paths never shipped (health checks / probes). Laravel wildcards, comma-separated.
# Default: up,up/*,health,healthz,ping,telescope*,horizon*,_debugbar/*
# OBSERVERA_IGNORE_PATHS=up,health,metrics

# Max captured outbound HTTP body size in bytes (redacted first, then cut on a
# UTF-8 boundary). 0 = no cap. OBSERVERA_CAPTURE_BODIES=false disables bodies.
# OBSERVERA_MAX_BODY=8192

# Report Eloquent N+1s (lazy loading a relation on a model fetched in a list)
# as warnings. Non-throwing: the relation still loads. Installed after all your
# providers boot and skipped if your app calls Model::preventLazyLoading() itself
# (your exception keeps working). Note: when installed it turns lazy-loading
# prevention ON with a non-throwing handler, so Model::preventsLazyLoading()
# reports true afterwards.
# OBSERVERA_CAPTURE_LAZY_LOADING=true
```

That's it. `Log::error('Payment failed', ['order' => $id])` now appears in
Observera under Logs, filterable by level / channel / search / trace id.

> **Performance.** By default (`OBSERVERA_QUEUE` unset) the batch is sent
> **inline after the response**: during an HTTP request nothing ships — events
> are buffered and posted in `terminate`, which runs after `Response::send()`
> (and after `fastcgi_finish_request()` under PHP-FPM), so users never wait on
> it. A circuit breaker (below) bounds the cost when Observera is unreachable.
>
> **Async shipping (recommended for busy apps / Octane):** `OBSERVERA_QUEUE=redis`.
> The ship job goes to a dedicated `observera` queue (`OBSERVERA_QUEUE_NAME`), so
> make a worker listen to it: `php artisan queue:work redis --queue=default,observera`.
> The job is encrypted at rest (`ShouldBeEncrypted`), has a 10s timeout, at most
> 3 tries within 10 minutes, and a **failed batch is discarded** — it is never
> left in `failed_jobs`, and nothing is logged. The `database` queue works too but
> writes every batch to your primary DB; prefer Redis. If the queue push itself
> fails, the SDK falls back to inline delivery. The ship job is excluded from
> instrumentation, so it never loops.

### Circuit breaker

A failed send (connection error, timeout, HTTP 5xx or 429) opens the breaker:
for the next 30s every batch is **dropped** (not buffered, not queued) without
touching the network. Each further failure doubles the cool-down, up to 5 min;
a 429 uses the server's `Retry-After` instead. One success closes it. State is
kept in your default cache store under `observera:breaker` (shared by all
FPM workers / queue workers; a per-process fallback is used if the cache is
down), so a dead or blackholed ingest costs at most one timeout per cool-down
window per app — not one per request. `observera:test` bypasses the breaker.

Other limits, all to protect the host app: an envelope never exceeds 1,000
events or ~15MB (bigger flushes are split; a single oversized event has its
long strings cut to 64KB), at most 5,000 events are buffered in-process (the
rest are counted, dropped, and reported once as a warning log), and per-request
query / outbound-call lists are capped at 500 (the request row still carries the
full `db_queries` count). Queue and schedule workers reset these per job / task.

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
  errors, never following redirects, TLS verification on. Plain `http://`
  endpoints are refused (logged once via `error_log`) except `localhost`,
  `127.0.0.1`, `::1`, `*.test` and `*.local`. JSON is encoded with invalid UTF-8
  substituted, so one bad string never drops a batch.
- Dormant when `OBSERVERA_KEY` is unset or `OBSERVERA_ENABLED=false`.
- Queues: every dispatched job is stamped (`Queue::createPayloadUsing`) with its
  enqueue time and the request/command/job that dispatched it, so job rows carry
  a real queue wait time (dispatch → start, first attempt) and link back to the
  request trace. Also records attempts released for retry, timeouts, manual
  `queue:retry`, and each worker exit with its reason (logged as
  `Queue worker stopped: …`).
- N+1: a lazy-loaded relation on a model that came from a multi-row query is
  reported once per request as `N+1 query: lazy loading Model::relation`.

## Distributed tracing

Trace ids are W3C-compatible (32 lowercase hex), so a request that crosses
services stays one trace:

- **Incoming** — a request carrying `traceparent: 00-<trace-id>-<parent-id>-<flags>`
  continues that trace: its events use the incoming trace-id and the request
  row records `parent_span_id` (the caller's span). Invalid / all-zero headers
  are ignored and a fresh trace starts.
- **Outgoing** — every call made with Laravel's `Http` client gets a
  `traceparent` with the current trace-id and a new span id per call (plus the
  incoming `tracestate`, passed through). That span id is stored on the
  `http_out` event as `span_id`, so the downstream request's `parent_span_id`
  links back to it. Uses `Http::globalRequestMiddleware` (Laravel 10.x+); a
  `traceparent` you set yourself is left alone.
- **Queues** — a job continues the trace of the request that dispatched it, so
  logs, exceptions and HTTP calls inside the job share the request's trace.

```dotenv
# OBSERVERA_PROPAGATE_TRACE=true
# Hosts that never get the headers (Laravel wildcards, comma-separated). A few
# third-party APIs reject unknown headers — rare, but list them here if so.
# OBSERVERA_PROPAGATE_TRACE_DENY=api.example.com,*.legacy.internal
# Allow-list: when set, ONLY these hosts get the headers (empty = all hosts).
# OBSERVERA_PROPAGATE_TRACE_HOSTS=*.internal.example.com
# Ignore incoming traceparent (e.g. a public API where callers shouldn't pick ids).
# OBSERVERA_TRUST_INCOMING_TRACE=true
```

By default the headers go to **every** host, third-party APIs included (they
see a random trace id, nothing else). An incoming `tracestate` is forwarded only
if it is valid W3C (≤ 512 chars, ≤ 32 `key=value` members); otherwise dropped.

Look a trace up across all your projects with `GET /api/v1/traces/{traceId}`.

## Security & data captured

What leaves your app, and what is done to it first:

- **Log / exception context, job metadata** — recursively redacted (depth 5,
  strings capped at 8KB). Values under keys matching `redact_keys` become
  `[Filtered]`. Objects become labels: a model is `App\Models\User#12` (never its
  attributes), a `Throwable` `{class, message}`, `Stringable` its (capped)
  string, `JsonSerializable` its redacted data.
- **SQL** — query text with bindings as `?`; string and numeric literals in raw
  SQL (`whereRaw`, `DB::statement`) are replaced with `?`. A `QueryException`'s
  message (and the log line Laravel writes for it) carries the `?` SQL, not the
  bindings-interpolated one. The database driver's own error text is kept.
- **Outbound HTTP** — headers whose name contains
  `signature|auth|key|credential|session|jwt|subscription|token|secret|cookie|password`
  are masked; a `Location` header loses its query string. Bodies: JSON and form
  bodies are parsed and redacted by key, anything else gets a regex pass, then
  capped at `max_body`. Streamed (`stream`) or file-backed (`sink`) bodies are
  never read; multipart bodies are recorded as `[multipart N bytes]`.
- **Jobs** — a metadata summary (`displayName, uuid, maxTries, timeout, backoff,
  delay, retryUntil`), not the serialized command, unless `capture_job_payload`.
- **Scheduled tasks** — the last `max_output` bytes of the output file, regex-redacted.
- **Identity** — user id, email and name (`send_pii=false`: id only, no user
  agent). Session ids are never sent raw: `session_key` is
  `web:` + HMAC-SHA256(session id, `APP_KEY`) (32 hex). IPs are never sent; the
  anonymous visitor id is an HMAC of ip|user-agent.
- **Cache** — the key's shape (`orders:{id}`) plus a 12-hex HMAC of the raw key;
  values are never sent, only their size (0 for objects).
- **Stack traces** — paths relative to your app root; ±5-line source snippets
  for app frames (`capture_source`), never for `config/`, compiled views or `.env*`.

## Config

`config/observera.php` (publish with `php artisan observera:install`):

| Key | Env | Default | |
|---|---|---|---|
| `key` | `OBSERVERA_KEY` | — | ingest key; SDK is dormant without it |
| `endpoint` | `OBSERVERA_ENDPOINT` | hosted ingest | must be https (http only for local hosts) |
| `environment` | `OBSERVERA_ENV` | `APP_ENV` | |
| `release` | `OBSERVERA_RELEASE` | git sha | |
| `enabled` | `OBSERVERA_ENABLED` | `true` | |
| `level` | `OBSERVERA_LEVEL` | `debug` | min log level shipped |
| `batch_size` | `OBSERVERA_BATCH_SIZE` | `50` | flush trigger outside HTTP requests (cache rows don't count) |
| `timeout` / `connect_timeout` | `OBSERVERA_TIMEOUT` / `_CONNECT_TIMEOUT` | `2.0` / `1.0` | seconds |
| `queue` | `OBSERVERA_QUEUE` | `null` (inline, after response) | `redis` recommended |
| `queue_name` | `OBSERVERA_QUEUE_NAME` | `observera` | dedicated queue |
| `capture_bodies` | `OBSERVERA_CAPTURE_BODIES` | `true` | outbound HTTP bodies at all |
| `max_body` | `OBSERVERA_MAX_BODY` | `8192` | bytes; 0 = no cap |
| `capture_job_payload` | `OBSERVERA_CAPTURE_JOB_PAYLOAD` | `false` | raw (redacted) queue payload |
| `max_output` | `OBSERVERA_MAX_OUTPUT` | `65536` | scheduled output tail, bytes; 0 = all |
| `redact_keys` | `OBSERVERA_REDACT_KEYS` (appended) | `pass(word)?, secret, token, api[_-]?key, auth, cookie, session, card, cvv, ssn, private[_-]?key, credential` | regex fragments, case-insensitive, match anywhere in the key |
| `send_pii` | `OBSERVERA_SEND_PII` | `true` | false = user id only, no email/name/UA |
| `capture_source` | `OBSERVERA_CAPTURE_SOURCE` | `true` | source snippets on app frames |
| `user_email_attribute` / `user_name_attribute` | `OBSERVERA_USER_*_ATTRIBUTE` | `email` / `name` | |
| `capture_lazy_loading` | `OBSERVERA_CAPTURE_LAZY_LOADING` | `true` | N+1 warnings (see above) |
| `ignore_paths` | `OBSERVERA_IGNORE_PATHS` | `up,health,ping,…` | |
| `propagate_trace` | `OBSERVERA_PROPAGATE_TRACE` | `true` | |
| `propagate_trace_hosts` | `OBSERVERA_PROPAGATE_TRACE_HOSTS` | empty = all | allow-list |
| `propagate_trace_deny` | `OBSERVERA_PROPAGATE_TRACE_DENY` | empty | deny-list |
| `trust_incoming_trace` | `OBSERVERA_TRUST_INCOMING_TRACE` | `true` | continue incoming `traceparent` |

## Payload

`POST {endpoint}/intake/events`, header `x-observera-key`, body:

```json
{ "logs": [ { "level": "error", "message": "...", "channel": "production",
             "context": {...}, "trace_id": "...", "timestamp": 1721470000000 } ] }
```

## Test

```bash
composer install
composer test        # PHPUnit + Orchestra Testbench (same as vendor/bin/phpunit)
composer analyse     # PHPStan (larastan), level 5
```

`tests/Unit` is framework-free; `tests/Feature` boots a Testbench app (in-memory
sqlite, database queue). `ReliabilityTest` starts its own `php -S` ingest on a
free local port; its server-backed tests are skipped if that server cannot start.

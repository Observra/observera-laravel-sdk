<?php

return [
    // Ingest key from Observera → project → environment (obs_live_… / obs_test_…).
    'key' => env('OBSERVERA_KEY'),

    // Ingest base URL. Prod: https://api.observera.clipnexor.com. Local Docker: http://api.observera.test
    'endpoint' => env('OBSERVERA_ENDPOINT', 'https://api.observera.clipnexor.com'),

    // Logical environment label attached to every event.
    'environment' => env('OBSERVERA_ENV', env('APP_ENV', 'production')),

    // Release identifier — powers release health + adoption. Optional: if unset,
    // the SDK auto-detects the current git commit sha at boot. Override only if
    // you want a custom label (e.g. a version tag) or your deploy has no .git.
    'release' => env('OBSERVERA_RELEASE', env('APP_VERSION', '')),

    // Master switch. Auto-disabled when no key is set.
    'enabled' => env('OBSERVERA_ENABLED', true),

    // Minimum level to ship (debug|info|notice|warning|error|critical|alert|emergency).
    'level' => env('OBSERVERA_LEVEL', 'debug'),

    // Flush the buffer once it reaches this many records (also flushed on shutdown).
    'batch_size' => (int) env('OBSERVERA_BATCH_SIZE', 50),

    // HTTP timeouts (seconds). Kept short — shipping must never slow the app.
    'timeout' => (float) env('OBSERVERA_TIMEOUT', 2.0),
    'connect_timeout' => (float) env('OBSERVERA_CONNECT_TIMEOUT', 1.0),

    // Queue connection for async shipping. Default null = inline: the batch is
    // sent in terminate, AFTER the response is out, behind a circuit breaker
    // (a dead ingest costs one timeout per cool-down, not one per request).
    // Recommended for busy apps / Octane: OBSERVERA_QUEUE=redis. The ship job is
    // encrypted, retried briefly and then discarded (never left in failed_jobs).
    'queue' => env('OBSERVERA_QUEUE') ?: null,

    // Host metrics for the Servers page (CPU, memory, disk, load, network from
    // /proc, Linux only). Sampled by queue workers every 15s — no extra process.
    // `name` overrides the host name (recommended in Docker, where the hostname
    // is the container id); `role` labels it (web | worker | scheduler).
    'host' => [
        'metrics' => env('OBSERVERA_HOST_METRICS', true),
        'name' => env('OBSERVERA_HOST_NAME'),
        'role' => env('OBSERVERA_HOST_ROLE'),
    ],

    // Dedicated queue for the ship job — make a worker listen to it
    // (php artisan queue:work redis --queue=default,observera).
    'queue_name' => env('OBSERVERA_QUEUE_NAME', 'observera'),

    // Capture request/response bodies at all: outbound HTTP calls and incoming
    // requests (GET query strings, form/JSON bodies; never uploaded files).
    'capture_bodies' => env('OBSERVERA_CAPTURE_BODIES', true),

    // Max captured body size in BYTES (outbound HTTP req/resp, opt-in job
    // payloads). Bodies are redacted (see redact_keys), then cut on a UTF-8
    // boundary and marked. 0 = no cap (the API still cuts each field at 64KB).
    'max_body' => (int) env('OBSERVERA_MAX_BODY', 8192),

    // Ship the raw (redacted, capped) queue payload on job rows. Off by default:
    // the payload is the serialized command — constructor args, often PII.
    // Off = a metadata summary (displayName, uuid, maxTries, timeout, backoff, delay).
    'capture_job_payload' => env('OBSERVERA_CAPTURE_JOB_PAYLOAD', false),

    // Scheduled-task output: only the last N BYTES of the output file are read. 0 = all.
    'max_output' => (int) env('OBSERVERA_MAX_OUTPUT', 65536),

    // Keys (regex fragments, case-insensitive, matched anywhere in the key) whose
    // values become "[Filtered]" in log/exception context, job metadata and
    // captured bodies. Add your own with OBSERVERA_REDACT_KEYS=iban,dob (comma-separated).
    'redact_keys' => array_merge(
        ['pass(word)?', 'secret', 'token', 'api[_-]?key', 'auth', 'cookie', 'session', 'card', 'cvv', 'ssn', 'private[_-]?key', 'credential'],
        array_values(array_filter(array_map('trim', explode(',', (string) env('OBSERVERA_REDACT_KEYS', ''))), fn ($k) => $k !== '')),
    ),

    // Send user email / name and the raw user agent. false = user id only (IPs
    // are never sent raw either way — only as a keyed hash).
    'send_pii' => env('OBSERVERA_SEND_PII', true),

    // Source snippets (±5 lines) on app stack frames. Never for config/,
    // compiled views or .env files.
    'capture_source' => env('OBSERVERA_CAPTURE_SOURCE', true),

    // Attributes read off the authenticated user for `user_email` / `user_name`.
    // Override if your user model names them differently.
    'user_email_attribute' => env('OBSERVERA_USER_EMAIL_ATTRIBUTE', 'email'),
    'user_name_attribute' => env('OBSERVERA_USER_NAME_ATTRIBUTE', 'name'),

    // Report Eloquent N+1s (lazy loading a relation on a model fetched in a
    // list) as warnings. Non-throwing: the relation still loads. Installed after
    // all providers boot, and skipped when the app itself already called
    // Model::preventLazyLoading() (your exception-throwing setting wins).
    'capture_lazy_loading' => env('OBSERVERA_CAPTURE_LAZY_LOADING', true),

    // Request paths to ignore (never shipped) — health checks / probes that
    // container HEALTHCHECKs and load balancers hit constantly. Supports Laravel
    // wildcards (e.g. "telescope*"). Comma-separated in the env var.
    'ignore_paths' => array_values(array_filter(array_map('trim', explode(
        ',',
        (string) env('OBSERVERA_IGNORE_PATHS', 'up,up/*,health,healthz,ping,telescope*,horizon*,_debugbar/*'),
    )), fn ($p) => $p !== '')),

    // Distributed tracing: send a W3C `traceparent` (and pass through an
    // incoming `tracestate`) on every outbound Laravel Http client call, so the
    // downstream service's request joins this trace. Incoming `traceparent`
    // headers are always honoured. A few third-party APIs reject unknown
    // headers (rare) — list their hosts in the deny-list (Laravel wildcards,
    // e.g. "*.example.com"; comma-separated in the env var). Default: propagate
    // to every host, third-party APIs included (they see a random trace id).
    'propagate_trace' => env('OBSERVERA_PROPAGATE_TRACE', true),

    // Allow-list: when non-empty, ONLY these hosts get the headers (the deny-list
    // still applies on top). Empty = all hosts.
    'propagate_trace_hosts' => array_values(array_filter(array_map('trim', explode(
        ',',
        (string) env('OBSERVERA_PROPAGATE_TRACE_HOSTS', ''),
    )), fn ($h) => $h !== '')),

    // Continue an incoming `traceparent`. Set false on apps exposed to the
    // public internet if you don't want callers choosing your trace ids.
    'trust_incoming_trace' => env('OBSERVERA_TRUST_INCOMING_TRACE', true),
    'propagate_trace_deny' => array_values(array_filter(array_map('trim', explode(
        ',',
        (string) env('OBSERVERA_PROPAGATE_TRACE_DENY', ''),
    )), fn ($h) => $h !== '')),
];

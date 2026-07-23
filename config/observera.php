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

    // Ship off-thread via a queue connection instead of a blocking HTTP POST on
    // every request (esp. important under Octane). Defaults to "database" so
    // shipping is async out of the box; set OBSERVERA_QUEUE=redis for Redis, or
    // "sync"/null to ship inline. If the connection's backing store is missing
    // (e.g. no `jobs` table), the SDK safely falls back to inline delivery.
    'queue' => env('OBSERVERA_QUEUE', 'database'),

    // Queue name for the ship job when 'queue' is set.
    'queue_name' => env('OBSERVERA_QUEUE_NAME', 'default'),

    // Max captured body size in BYTES (outbound HTTP req/resp, job payloads).
    // Bodies above this are truncated on a UTF-8 boundary with a marker. Keep it
    // bounded — shipping multi-MB bodies bloats storage and slows ingest.
    'max_body' => (int) env('OBSERVERA_MAX_BODY', 65536),

    // Request paths to ignore (never shipped) — health checks / probes that
    // container HEALTHCHECKs and load balancers hit constantly. Supports Laravel
    // wildcards (e.g. "telescope*"). Comma-separated in the env var.
    'ignore_paths' => array_values(array_filter(array_map('trim', explode(
        ',',
        (string) env('OBSERVERA_IGNORE_PATHS', 'up,up/*,health,healthz,ping,telescope*,horizon*,_debugbar/*'),
    )), fn ($p) => $p !== '')),
];

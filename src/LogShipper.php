<?php

namespace Observera\Laravel;

use Observera\Laravel\Jobs\ShipEnvelope;
use Observera\Laravel\Transport\Client;

/**
 * Buffers telemetry events (logs, requests, exceptions, outbound HTTP) and
 * flushes them to Observera in one envelope. One instance per process
 * (singleton). Flush triggers: buffer hits batch_size, and on request/command
 * shutdown (registered by the service provider).
 */
class LogShipper
{
    /** Event types, in envelope order. @var array<string, array<int, array<string, mixed>>> */
    protected array $buffers = [
        'logs' => [], 'requests' => [], 'exceptions' => [], 'http_out' => [], 'queries' => [],
        'spans' => [], 'jobs' => [], 'cache' => [], 'scheduled' => [], 'sessions' => [],
    ];

    /** Events buffered across all types. */
    protected int $total = 0;

    /** Events refused because the buffer was at HARD_CAP (reported once at the next flush). */
    protected int $dropped = 0;

    /**
     * While true (an HTTP request is in flight) batch_size never triggers a
     * flush: everything ships in terminate, after the response is sent. Only an
     * explicit flush(force: true) ships early.
     */
    public bool $deferred = false;

    // reentrancy guard: shipping must not record its own log lines
    protected bool $flushing = false;

    /** Hard in-process buffer cap: past it events are counted and dropped, never held. */
    public const HARD_CAP = 5000;

    /** Cache rows kept per flush window (≈ per request); they never trigger a flush. */
    public const MAX_CACHE = 200;

    /** API limits: 1,000 events and 20MB per envelope. We split well below the byte limit. */
    public const MAX_EVENTS = 1000;

    public const MAX_ENVELOPE_BYTES = 15_000_000;

    /** The API cuts every field to 64KB anyway, so an oversized event loses nothing it would keep. */
    public const MAX_FIELD_BYTES = 65536;

    /** Redaction limits for context / bodies / metadata. */
    public const MAX_DEPTH = 5;

    public const MAX_STRING = 8192;

    /** Key fragments (regex) whose values are masked — set from config('observera.redact_keys'). */
    public static string $redactKeys = 'pass(word)?|secret|token|api[_-]?key|auth|cookie|session|card|cvv|ssn|private[_-]?key|credential';

    /** @var array<string, int> monolog-style severity ranking */
    protected const LEVELS = [
        'debug' => 100, 'info' => 200, 'notice' => 250, 'warning' => 300,
        'error' => 400, 'critical' => 500, 'alert' => 550, 'emergency' => 600,
    ];

    public function __construct(
        protected Client $client,
        protected string $environment,
        protected int $batchSize = 50,
        protected string $minLevel = 'debug',
        protected ?string $queue = null,
        protected string $queueName = 'default',
    ) {}

    /**
     * Record one log line (from the MessageLogged event). Cheap: buffers only.
     *
     * @param  array{id?: string, email?: string, name?: string}  $user  authenticated
     *         identity, so a log line says WHO it happened to and not just what
     */
    public function record(string $level, string $message, array $context = [], array $user = []): void
    {
        if ($this->flushing || ! $this->passesLevel($level)) {
            return;
        }

        $this->push('logs', [
            'level' => $level,
            'message' => self::capBody($message, self::MAX_FIELD_BYTES),
            'channel' => $this->environment,
            'context' => $this->scrub($context),
            'file' => '',
            'trace_id' => (string) ($context['trace_id'] ?? ''),
            'user_id' => (string) ($user['id'] ?? ''),
            'user_email' => (string) ($user['email'] ?? ''),
            'user_name' => (string) ($user['name'] ?? ''),
            'timestamp' => round(microtime(true) * 1000),
        ]);
    }

    public function recordRequest(array $request): void
    {
        $this->push('requests', $request);
    }

    public function recordException(array $exception): void
    {
        $this->push('exceptions', $exception);
    }

    public function recordHttpOut(array $call): void
    {
        $this->push('http_out', $call);
    }

    /**
     * @param  array<int, array<string, mixed>>  $queries
     */
    public function recordQueries(array $queries): void
    {
        foreach ($queries as $q) {
            $this->push('queries', $q);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $spans
     */
    public function recordSpans(array $spans): void
    {
        foreach ($spans as $s) {
            $this->push('spans', $s);
        }
    }

    public function recordJob(array $job): void
    {
        $this->push('jobs', $job);
    }

    public function recordCache(array $op): void
    {
        $this->push('cache', $op);
    }

    public function recordScheduled(array $run): void
    {
        $this->push('scheduled', $run);
    }

    public function recordSession(array $session): void
    {
        $this->push('sessions', $session);
    }

    /**
     * Ship everything buffered. Split into envelopes the API accepts (≤ 1,000
     * events, ≤ ~15MB). While deferred (HTTP request in flight) only a forced
     * flush ships — the request's own terminate does that after the response.
     */
    public function flush(bool $force = false): void
    {
        if ($this->flushing || ($this->deferred && ! $force)) {
            return;
        }

        if ($this->dropped > 0) {
            $this->buffers['logs'][] = [
                'level' => 'warning',
                'message' => "Observera SDK dropped {$this->dropped} events: in-process buffer cap of ".self::HARD_CAP.' reached',
                'channel' => $this->environment,
                'context' => ['observera' => 'buffer_overflow', 'dropped' => $this->dropped],
                'file' => '', 'trace_id' => '', 'user_id' => '', 'user_email' => '', 'user_name' => '',
                'timestamp' => round(microtime(true) * 1000),
            ];
            $this->dropped = 0;
        }

        $env = array_filter($this->buffers);
        if ($env === []) {
            return;
        }

        $this->flushing = true;
        $this->buffers = array_map(fn () => [], $this->buffers);
        $this->total = 0;

        try {
            foreach (self::chunk($env) as $chunk) {
                $this->ship($chunk);
            }
        } finally {
            $this->flushing = false;
        }
    }

    protected function ship(array $env): void
    {
        if ($this->queue === null) {
            $this->client->sendEnvelope($env);

            return;
        }

        if (Client::breakerOpen()) {
            return; // ingest is failing: drop, don't pile batches into the queue
        }

        // Off-thread: push to the queue and return immediately. Guarded so a
        // broker hiccup can never break the app (same promise as the sync path).
        try {
            ShipEnvelope::dispatch(self::utf8($env))->onConnection($this->queue)->onQueue($this->queueName);
        } catch (\Throwable) {
            // fall back to inline delivery if the queue push itself fails
            $this->client->sendEnvelope($env);
        }
    }

    /**
     * Split an envelope so each part stays under the API's event and byte
     * limits. Sizes are measured per event; one event too big on its own has
     * its long strings cut to the API's field cap (the API would cut them
     * anyway), and is dropped only if it is still too big after that.
     *
     * @return array<int, array<string, array<int, array<string, mixed>>>>
     */
    public static function chunk(array $env): array
    {
        $chunks = [];
        $cur = [];
        $count = $bytes = 0;

        foreach ($env as $type => $events) {
            foreach ($events as $event) {
                $size = strlen(Client::encode($event));
                if ($size > self::MAX_ENVELOPE_BYTES) {
                    $event = self::truncateStrings($event, self::MAX_FIELD_BYTES);
                    $size = strlen(Client::encode($event));
                    if ($size > self::MAX_ENVELOPE_BYTES) {
                        continue;
                    }
                }
                if ($count > 0 && ($count >= self::MAX_EVENTS || $bytes + $size > self::MAX_ENVELOPE_BYTES)) {
                    $chunks[] = $cur;
                    $cur = [];
                    $count = $bytes = 0;
                }
                $cur[$type][] = $event;
                $count++;
                $bytes += $size + 1;
            }
        }

        if ($cur !== []) {
            $chunks[] = $cur;
        }

        return $chunks;
    }

    protected static function truncateStrings(mixed $v, int $max): mixed
    {
        if (is_string($v)) {
            return self::capBody($v, $max);
        }
        if (is_array($v)) {
            return array_map(fn ($x) => self::truncateStrings($x, $max), $v);
        }

        return $v;
    }

    /** Make every string valid UTF-8 (a queued payload must serialise/encode cleanly). */
    public static function utf8(array $data): array
    {
        array_walk_recursive($data, function (&$v) {
            if (is_string($v) && ! mb_check_encoding($v, 'UTF-8')) {
                $v = mb_scrub($v, 'UTF-8');
            }
        });

        return $data;
    }

    /**
     * Buffer one event. Past HARD_CAP events are counted and dropped (bounded
     * memory, no mid-request network). Cache rows are capped per flush window
     * and never trigger a flush on their own.
     */
    protected function push(string $type, array $event): void
    {
        if ($this->flushing) {
            return;
        }
        if ($type === 'cache' && count($this->buffers['cache']) >= self::MAX_CACHE) {
            return;
        }
        if ($this->total >= self::HARD_CAP) {
            $this->dropped++;

            return;
        }

        $this->buffers[$type][] = $event;
        $this->total++;

        if ($type !== 'cache' && ! $this->deferred && $this->total - count($this->buffers['cache']) >= $this->batchSize) {
            $this->flush();
        }
    }

    protected function passesLevel(string $level): bool
    {
        $rank = self::LEVELS[strtolower($level)] ?? 200;
        $min = self::LEVELS[strtolower($this->minLevel)] ?? 100;

        return $rank >= $min;
    }

    /** Header names whose values are masked before shipping (any name containing one of these). */
    public const SENSITIVE_HEADER = '/signature|auth|key|credential|session|jwt|subscription|token|secret|cookie|password/i';

    /**
     * Flatten + mask headers. Values for sensitive names (or any header whose
     * name hints at a secret) are redacted before leaving the app.
     *
     * @param  array<string, array<int, string>|string>  $headers
     * @return array<string, string>
     */
    public static function maskHeaders(array $headers): array
    {
        $out = [];

        foreach ($headers as $name => $values) {
            $value = is_array($values) ? implode(', ', $values) : (string) $values;
            $lower = strtolower((string) $name);

            if ($lower === 'location') {
                $value = preg_replace('/\?.*$/s', '', $value); // signed redirect URLs carry tokens in the query
            }

            $out[$name] = preg_match(self::SENSITIVE_HEADER, $lower) ? self::maskValue($value) : $value;
        }

        return $out;
    }

    public static function maskValue(string $value): string
    {
        // keep a scheme prefix (e.g. "Bearer") + a short tail, redact the middle
        if (preg_match('/^(\w+)\s+(.+)$/', $value, $m)) {
            return $m[1].' '.'••••••••'.'(masked)';
        }

        return '••••••••(masked)';
    }

    /**
     * Sanitise a payload for shipping, and cap it only if a cap is configured.
     *
     * `$max <= 0` means capture in full — nothing is trimmed. That is what you
     * want when the payload IS the evidence (a third-party response you need to
     * replay), and it is the default. This has to be spelled out because the
     * obvious reading of `mb_strcut($body, 0, 0)` is an empty string, and a
     * version that shipped without this guard stored bodies that were nothing
     * but a truncation marker.
     *
     * Cutting, when a cap applies, is UTF-8 aware: `mb_strcut` trims to a byte
     * budget WITHOUT splitting a multibyte character (byte `substr` would corrupt
     * Japanese/emoji etc., and an invalid-UTF-8 tail makes json_encode fail on
     * the whole envelope → lost data). Invalid bytes in the source are sanitised
     * for the same reason.
     *
     * Lives here, not on the service provider, because it is framework-free and
     * therefore testable — see tests/Unit/LogShipperTest.php.
     */
    public static function capBody(string $body, int $max): string
    {
        // Guarantee valid UTF-8 so the JSON envelope always encodes.
        if (! mb_check_encoding($body, 'UTF-8')) {
            $sub = mb_substitute_character();
            mb_substitute_character(0xFFFD);
            $body = mb_convert_encoding($body, 'UTF-8', 'UTF-8');
            mb_substitute_character($sub);
        }

        // 0 (or negative) disables the cap — store everything.
        if ($max <= 0 || strlen($body) <= $max) {
            return $body;
        }

        // Both numbers, because one alone is ambiguous: "truncated 65970 bytes"
        // reads as "65970 bytes were dropped" when it was the original size.
        $kept = mb_strcut($body, 0, $max, 'UTF-8');

        return $kept."\n… (truncated — stored ".strlen($kept).' of '.strlen($body).' bytes)';
    }

    /** Log context → JSON-safe, redacted, size-bounded. */
    protected function scrub(array $context): array
    {
        return (array) self::redact($context);
    }

    /** True when a key names something secret (password, token, cookie, card …). */
    public static function sensitiveKey(string $key): bool
    {
        return (bool) @preg_match('~(?:'.self::$redactKeys.')~i', $key);
    }

    /**
     * Recursively redact a value for shipping: values under a sensitive KEY
     * become "[Filtered]", strings are capped (and made valid UTF-8), depth is
     * capped, and objects become a short label — a model is "App\Models\User#12",
     * never its attributes.
     */
    public static function redact(mixed $value, int $depth = 0): mixed
    {
        if (is_string($value)) {
            return self::capBody($value, self::MAX_STRING);
        }
        if ($value === null || is_scalar($value)) {
            return $value;
        }
        if ($depth >= self::MAX_DEPTH) {
            return is_array($value) ? '[array('.count($value).')]' : '[object '.get_debug_type($value).']';
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = is_string($k) && self::sensitiveKey($k) ? '[Filtered]' : self::redact($v, $depth + 1);
            }

            return $out;
        }

        try {
            if ($value instanceof \Illuminate\Database\Eloquent\Model) {
                $id = $value->getKey();

                return $value::class.(is_scalar($id) ? '#'.$id : '');
            }
            if ($value instanceof \Throwable) {
                return ['class' => $value::class, 'message' => self::capBody(self::exceptionMessage($value), self::MAX_STRING)];
            }
            if ($value instanceof \JsonSerializable) {
                return self::redact($value->jsonSerialize(), $depth + 1);
            }
            if ($value instanceof \Stringable) {
                return self::capBody((string) $value, self::MAX_STRING);
            }
        } catch (\Throwable) {
            // a misbehaving __toString / jsonSerialize: fall through to the label
        }

        return '[object '.get_debug_type($value).']';
    }

    /**
     * Exception message safe to ship. A QueryException's message embeds the SQL
     * with its bindings interpolated (emails, tokens …) — swap that for the SQL
     * with `?` placeholders.
     */
    public static function exceptionMessage(\Throwable $e): string
    {
        $message = $e->getMessage();
        if (! $e instanceof \Illuminate\Database\QueryException) {
            return $message;
        }

        $sql = $e->getSql();

        return preg_replace_callback('/\(Connection: (.*?), SQL: .*\)$/s', fn ($m) => '(Connection: '.$m[1].', SQL: '.$sql.')', $message) ?? $message;
    }

    /**
     * Redact a captured HTTP body before capping it: JSON and form bodies are
     * parsed and their sensitive keys masked; anything else (or unparsable)
     * gets a regex pass over `"key": "value"` / `key=value` shapes.
     */
    public static function redactBody(string $body, string $contentType, int $max): string
    {
        if ($body === '') {
            return '';
        }
        $ct = strtolower($contentType);

        if (strlen($body) > 1_048_576) {
            // too big to parse cheaply — regex pass over the head only
            $body = self::redactText(mb_strcut($body, 0, 1_048_576, 'UTF-8'));
        } elseif ((str_contains($ct, 'json') || in_array($body[0], ['{', '['], true)) && is_array($data = json_decode($body, true))) {
            $body = (string) Client::encode(self::redact($data));
        } elseif (str_contains($ct, 'x-www-form-urlencoded')) {
            parse_str($body, $data);
            $body = http_build_query(self::redact($data));
        } else {
            $body = self::redactText($body);
        }

        return self::capBody($body, $max);
    }

    /** Regex redaction for text we cannot parse: JSON-ish, query-string and PHP-serialized shapes. */
    public static function redactText(string $text): string
    {
        $k = '(?:'.self::$redactKeys.')';

        // (?<!:) — a PHP-serialized string (s:5:"…") is never a JSON key
        $text = @preg_replace('~((?<!:)"[^"]*'.$k.'[^"]*"\s*:\s*)(?:"(?:[^"\\\\]|\\\\.)*"?|[^,}\]\s]+)~i', '$1"[Filtered]"', $text) ?? $text;
        $text = @preg_replace('~((?:^|[&?\s;,])[^=&\s;,"]*'.$k.'[^=&\s;,"]*=)[^&\s;,]*~i', '$1[Filtered]', $text) ?? $text;

        return @preg_replace('~(s:\d+:"[^"]*'.$k.'[^"]*";s:\d+:")[^"]*"~i', '$1[Filtered]"', $text) ?? $text;
    }
}

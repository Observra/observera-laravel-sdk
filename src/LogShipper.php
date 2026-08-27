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
    /** @var array<int, array<string, mixed>> */
    protected array $logs = [];

    /** @var array<int, array<string, mixed>> */
    protected array $requests = [];

    /** @var array<int, array<string, mixed>> */
    protected array $exceptions = [];

    /** @var array<int, array<string, mixed>> */
    protected array $httpOut = [];

    /** @var array<int, array<string, mixed>> */
    protected array $queries = [];

    /** @var array<int, array<string, mixed>> */
    protected array $spans = [];

    /** @var array<int, array<string, mixed>> */
    protected array $jobs = [];

    /** @var array<int, array<string, mixed>> */
    protected array $cache = [];

    /** @var array<int, array<string, mixed>> */
    protected array $scheduled = [];

    /** @var array<int, array<string, mixed>> */
    protected array $sessions = [];

    // reentrancy guard: shipping must not record its own log lines
    protected bool $flushing = false;

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

        $this->logs[] = [
            'level' => $level,
            'message' => $message,
            'channel' => $this->environment,
            'context' => $this->scrub($context),
            'file' => '',
            'trace_id' => (string) ($context['trace_id'] ?? ''),
            'user_id' => (string) ($user['id'] ?? ''),
            'user_email' => (string) ($user['email'] ?? ''),
            'user_name' => (string) ($user['name'] ?? ''),
            'timestamp' => round(microtime(true) * 1000),
        ];

        $this->maybeFlush();
    }

    public function recordRequest(array $request): void
    {
        if ($this->flushing) {
            return;
        }
        $this->requests[] = $request;
        $this->maybeFlush();
    }

    public function recordException(array $exception): void
    {
        if ($this->flushing) {
            return;
        }
        $this->exceptions[] = $exception;
        $this->maybeFlush();
    }

    public function recordHttpOut(array $call): void
    {
        if ($this->flushing) {
            return;
        }
        $this->httpOut[] = $call;
        $this->maybeFlush();
    }

    /**
     * @param  array<int, array<string, mixed>>  $queries
     */
    public function recordQueries(array $queries): void
    {
        if ($this->flushing) {
            return;
        }
        foreach ($queries as $q) {
            $this->queries[] = $q;
        }
        $this->maybeFlush();
    }

    /**
     * @param  array<int, array<string, mixed>>  $spans
     */
    public function recordSpans(array $spans): void
    {
        if ($this->flushing) {
            return;
        }
        foreach ($spans as $s) {
            $this->spans[] = $s;
        }
        $this->maybeFlush();
    }

    public function recordJob(array $job): void
    {
        if ($this->flushing) {
            return;
        }
        $this->jobs[] = $job;
        $this->maybeFlush();
    }

    public function recordCache(array $op): void
    {
        if ($this->flushing) {
            return;
        }
        $this->cache[] = $op;
        $this->maybeFlush();
    }

    public function recordScheduled(array $run): void
    {
        if ($this->flushing) {
            return;
        }
        $this->scheduled[] = $run;
        $this->maybeFlush();
    }

    public function recordSession(array $session): void
    {
        if ($this->flushing) {
            return;
        }
        $this->sessions[] = $session;
        $this->maybeFlush();
    }

    public function flush(): void
    {
        if ($this->flushing) {
            return;
        }

        $env = array_filter([
            'logs' => $this->logs,
            'requests' => $this->requests,
            'exceptions' => $this->exceptions,
            'http_out' => $this->httpOut,
            'queries' => $this->queries,
            'spans' => $this->spans,
            'jobs' => $this->jobs,
            'cache' => $this->cache,
            'scheduled' => $this->scheduled,
            'sessions' => $this->sessions,
        ]);

        if ($env === []) {
            return;
        }

        $this->flushing = true;
        $this->logs = $this->requests = $this->exceptions = $this->httpOut = $this->queries = $this->spans = $this->jobs = $this->cache = $this->scheduled = $this->sessions = [];

        if ($this->queue !== null) {
            // Off-thread: push to the queue and return immediately. Guarded so a
            // broker hiccup can never break the app (same promise as the sync path).
            try {
                ShipEnvelope::dispatch($env)->onConnection($this->queue)->onQueue($this->queueName);
            } catch (\Throwable) {
                // fall back to inline delivery if the queue push itself fails
                $this->client->sendEnvelope($env);
            }
        } else {
            $this->client->sendEnvelope($env);
        }

        $this->flushing = false;
    }

    protected function maybeFlush(): void
    {
        $total = count($this->logs) + count($this->requests) + count($this->exceptions)
            + count($this->httpOut) + count($this->queries) + count($this->spans) + count($this->jobs)
            + count($this->cache) + count($this->scheduled) + count($this->sessions);
        if ($total >= $this->batchSize) {
            $this->flush();
        }
    }

    protected function passesLevel(string $level): bool
    {
        $rank = self::LEVELS[strtolower($level)] ?? 200;
        $min = self::LEVELS[strtolower($this->minLevel)] ?? 100;

        return $rank >= $min;
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
     * therefore testable — see tests/shipper_check.php.
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

    /**
     * Drop unserialisable values (e.g. Throwable objects) so the JSON body is
     * always valid; keep an exception's class+message if present.
     */
    protected function scrub(array $context): array
    {
        $out = [];

        foreach ($context as $key => $value) {
            if ($value instanceof \Throwable) {
                $out[$key] = ['class' => $value::class, 'message' => $value->getMessage()];
            } elseif (is_scalar($value) || is_array($value) || $value === null) {
                $out[$key] = $value;
            } else {
                $out[$key] = '[object '.get_debug_type($value).']';
            }
        }

        return $out;
    }
}

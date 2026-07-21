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
     */
    public function record(string $level, string $message, array $context = []): void
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
        ]);

        if ($env === []) {
            return;
        }

        $this->flushing = true;
        $this->logs = $this->requests = $this->exceptions = $this->httpOut = $this->queries = $this->spans = $this->jobs = $this->cache = $this->scheduled = [];

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
            + count($this->cache) + count($this->scheduled);
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

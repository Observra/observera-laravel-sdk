<?php

namespace Observera\Laravel;

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Observera\Laravel\Console\InstallCommand;
use Observera\Laravel\Console\TestCommand;
use Observera\Laravel\Http\ObserveraMiddleware;
use Observera\Laravel\Instrumentation\RequestMonitor;
use Observera\Laravel\Transport\Client;

class ObserveraServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/observera.php', 'observera');

        $this->app->singleton(RequestMonitor::class);

        $this->app->singleton(LogShipper::class, function ($app) {
            $config = $app['config']['observera'];

            return new LogShipper(
                new Client(
                    $config['endpoint'],
                    (string) ($config['key'] ?? ''),
                    (float) $config['timeout'],
                    (float) $config['connect_timeout'],
                ),
                (string) $config['environment'],
                (int) $config['batch_size'],
                (string) $config['level'],
                ($config['queue'] ?? null) ?: null, // empty string → inline
                (string) ($config['queue_name'] ?? 'default'),
            );
        });
    }

    /**
     * Best-effort release id from the app's git checkout (short commit sha).
     * Read once at boot; empty string if no readable .git (e.g. deploy without it).
     */
    protected function detectRelease(): string
    {
        try {
            $head = base_path('.git/HEAD');
            if (! is_readable($head)) {
                return '';
            }
            $ref = trim((string) file_get_contents($head));
            if (str_starts_with($ref, 'ref:')) {
                $refFile = base_path('.git/'.trim(substr($ref, 4)));

                return is_readable($refFile) ? substr(trim((string) file_get_contents($refFile)), 0, 12) : '';
            }

            return substr($ref, 0, 12); // detached HEAD → the sha itself
        } catch (\Throwable) {
            return '';
        }
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/observera.php' => $this->app->configPath('observera.php'),
        ], 'observera-config');

        if ($this->app->runningInConsole()) {
            $this->commands([InstallCommand::class, TestCommand::class]);
        }

        // Auto-resolve the release (for release health) once at boot if unset:
        // OBSERVERA_RELEASE → APP_VERSION → current git commit sha. So a deploy's
        // release is detected automatically without hardcoding a version.
        if ((string) config('observera.release', '') === '') {
            config(['observera.release' => $this->detectRelease()]);
        }

        $config = $this->app['config']['observera'];
        if (! $config['enabled'] || empty($config['key'])) {
            return; // no key → stay dormant, zero overhead
        }

        $shipper = $this->app->make(LogShipper::class);
        $monitor = $this->app->make(RequestMonitor::class);

        // ---- logs (+ exceptions carried in log context) ----
        $this->app['events']->listen(MessageLogged::class, function (MessageLogged $e) use ($shipper, $monitor) {
            $context = $e->context ?? [];
            $context['trace_id'] ??= $monitor->traceId;

            $shipper->record($e->level, $e->message, $context);

            // Laravel reports exceptions as error logs with context.exception — split those out
            if (isset($context['exception']) && $context['exception'] instanceof \Throwable) {
                $this->recordException($shipper, $monitor, $context['exception']);
            }
        });

        // ---- HTTP requests (timing + per-query telemetry) ----
        DB::listen(fn ($query) => $monitor->query($query->sql, $query->connectionName, (float) $query->time));

        try {
            $this->app->make(HttpKernel::class)->pushMiddleware(ObserveraMiddleware::class);
        } catch (\Throwable) {
            // console/queue contexts have no HTTP kernel — fine
        }

        // ---- outbound HTTP calls ----
        $this->app['events']->listen(ResponseReceived::class, function (ResponseReceived $e) use ($shipper, $monitor) {
            $url = (string) $e->request->url();
            $host = parse_url($url, PHP_URL_HOST) ?: 'unknown';
            $path = parse_url($url, PHP_URL_PATH) ?: '/';
            $stats = method_exists($e->response, 'handlerStats') ? $e->response->handlerStats() : [];
            $duration = round((float) (($stats['total_time'] ?? 0) * 1000), 1);

            $shipper->recordHttpOut([
                'service' => $host,
                'host' => $host,
                'method' => $e->request->method(),
                'url' => $path,
                'status' => $e->response->status(),
                'duration_ms' => $duration,
                'bytes' => strlen((string) $e->response->body()),
                'req_body' => $this->capBody((string) $e->request->body()),
                'resp_body' => $this->capBody((string) $e->response->body()),
                'req_headers' => $this->maskHeaders($e->request->headers()),
                'resp_headers' => $this->maskHeaders($e->response->headers()),
                'trace_id' => $monitor->traceId,
            ]);

            // feed the span waterfall
            $monitor->httpCall($host, $e->request->method().' '.$host.$path, $duration);
        });

        $this->app['events']->listen(ConnectionFailed::class, function (ConnectionFailed $e) use ($shipper, $monitor) {
            $url = (string) $e->request->url();
            $shipper->recordHttpOut([
                'service' => parse_url($url, PHP_URL_HOST) ?: 'unknown',
                'host' => parse_url($url, PHP_URL_HOST) ?: '',
                'method' => $e->request->method(),
                'url' => parse_url($url, PHP_URL_PATH) ?: '/',
                'status' => 0,
                'trace_id' => $monitor->traceId,
            ]);
        });

        // ---- queue jobs (run telemetry: duration, attempts, failures) ----
        // start times keyed by job id, set on JobProcessing and consumed on finish
        $jobStarts = [];
        $this->app['events']->listen(\Illuminate\Queue\Events\JobProcessing::class, function ($e) use (&$jobStarts) {
            if ($this->isOwnJob($e->job)) {
                return; // don't instrument our own ship job → no telemetry feedback loop
            }
            $jobStarts[$e->job->getJobId() ?: spl_object_id($e->job)] = microtime(true);
        });
        $this->app['events']->listen(\Illuminate\Queue\Events\JobProcessed::class, function ($e) use ($shipper, &$jobStarts) {
            if ($this->isOwnJob($e->job)) {
                return;
            }
            $shipper->recordJob($this->jobEvent($e, 'ok', $jobStarts));
        });
        $this->app['events']->listen(\Illuminate\Queue\Events\JobFailed::class, function ($e) use ($shipper, &$jobStarts) {
            if ($this->isOwnJob($e->job)) {
                return;
            }
            $ex = $e->exception ?? null;
            $shipper->recordJob($this->jobEvent($e, 'failed', $jobStarts, $ex
                ? $ex::class.' — '.$ex->getMessage()
                : ''));
        });

        // ---- cache operations (hit/miss/write/forget) ----
        $this->app['events']->listen(\Illuminate\Cache\Events\CacheHit::class, function ($e) use ($shipper, $monitor) {
            $shipper->recordCache($this->cacheOp('hit', $e, $monitor, $e->value ?? null));
        });
        $this->app['events']->listen(\Illuminate\Cache\Events\CacheMissed::class, function ($e) use ($shipper, $monitor) {
            $shipper->recordCache($this->cacheOp('miss', $e, $monitor));
        });
        $this->app['events']->listen(\Illuminate\Cache\Events\KeyWritten::class, function ($e) use ($shipper, $monitor) {
            $ttl = (int) ($e->seconds ?? 0);
            $shipper->recordCache($this->cacheOp('write', $e, $monitor, $e->value ?? null, $ttl > 0 ? $ttl : -1));
        });
        $this->app['events']->listen(\Illuminate\Cache\Events\KeyForgotten::class, function ($e) use ($shipper, $monitor) {
            $shipper->recordCache($this->cacheOp('forget', $e, $monitor));
        });

        // ---- scheduled tasks (cron) ----
        $this->app['events']->listen(\Illuminate\Console\Events\ScheduledTaskFinished::class, function ($e) use ($shipper, $monitor) {
            $exit = (int) ($e->task->exitCode ?? 0);
            $shipper->recordScheduled($this->scheduledRun($e->task, $exit === 0 ? 'ok' : 'failed', $monitor, round($e->runtime * 1000, 1), $exit));
        });
        $this->app['events']->listen(\Illuminate\Console\Events\ScheduledTaskFailed::class, function ($e) use ($shipper, $monitor) {
            $ex = $e->exception ?? null;
            $shipper->recordScheduled($this->scheduledRun($e->task, 'failed', $monitor, 0, 1,
                $ex ? $ex::class.' — '.$ex->getMessage() : ''));
        });
        $this->app['events']->listen(\Illuminate\Console\Events\ScheduledTaskSkipped::class, function ($e) use ($shipper, $monitor) {
            $shipper->recordScheduled($this->scheduledRun($e->task, 'skipped', $monitor, 0, 0));
        });

        // Flush at the end of the request/command/job.
        $this->app->terminating(fn () => $shipper->flush());
        register_shutdown_function(fn () => $shipper->flush());
    }

    /**
     * Build a job-run event from a queue event. `$starts` is keyed by job id and
     * mutated (consumed) here so duration survives between JobProcessing and finish.
     *
     * @param  array<string, float>  $starts
     * @return array<string, mixed>
     */
    /** Our own async ship job — never instrument it, or shipping telemetry ships telemetry forever. */
    protected function isOwnJob(object $job): bool
    {
        return $job->resolveName() === \Observera\Laravel\Jobs\ShipEnvelope::class;
    }

    protected function jobEvent(object $e, string $status, array &$starts, string $exception = ''): array
    {
        $job = $e->job;
        $key = $job->getJobId() ?: spl_object_id($job);
        $started = $starts[$key] ?? null;
        unset($starts[$key]);

        $payload = $job->payload();
        $data = $payload['data'] ?? [];

        return [
            'job_id' => (string) ($job->uuid() ?? $job->getJobId() ?? ''),
            'job_class' => (string) ($data['commandName'] ?? $job->resolveName()),
            'queue' => (string) ($job->getQueue() ?: 'default'),
            'connection' => (string) ($e->connectionName ?? ''),
            'status' => $status,
            'attempts' => (int) $job->attempts(),
            'max_attempts' => (int) ($payload['maxTries'] ?? 0),
            'duration_ms' => $started ? round((microtime(true) - $started) * 1000, 1) : 0,
            'worker' => gethostname() ?: '',
            'payload' => $this->capBody($job->getRawBody()),
            'exception' => $exception,
            'backoff' => (string) ($payload['backoff'] ?? ''),
            'timeout' => (int) ($payload['timeout'] ?? 0),
            'timestamp' => round(microtime(true) * 1000),
        ];
    }

    /**
     * Build a scheduled-task run event. Output is captured only when the task was
     * configured with ->sendOutputTo() to a readable file (capped).
     *
     * @return array<string, mixed>
     */
    protected function scheduledRun(object $task, string $status, RequestMonitor $monitor, float $durationMs, int $exit, string $exception = ''): array
    {
        $name = method_exists($task, 'getSummaryForDisplay') ? $task->getSummaryForDisplay() : (string) ($task->command ?? 'scheduled');
        $expr = method_exists($task, 'getExpression') ? $task->getExpression() : (string) ($task->expression ?? '');

        $output = '';
        $file = $task->output ?? null;
        if (is_string($file) && $file !== '' && $file !== '/dev/null' && is_readable($file) && filesize($file) < 65536) {
            $output = $this->capBody((string) @file_get_contents($file), 2000);
        }

        return [
            'task' => (string) $name,
            'expression' => (string) $expr,
            'status' => $status,
            'duration_ms' => $durationMs,
            'exit_code' => $exit,
            'output' => $output,
            'exception' => $exception,
            'host' => gethostname() ?: '',
            'trace_id' => $monitor->traceId,
            'timestamp' => round(microtime(true) * 1000),
        ];
    }

    /**
     * Build a cache-operation event. Only the value's byte size is shipped, never
     * the value itself (privacy + payload size).
     *
     * @return array<string, mixed>
     */
    protected function cacheOp(string $op, object $e, RequestMonitor $monitor, mixed $value = null, int $ttl = 0): array
    {
        $key = (string) ($e->key ?? '');
        $tags = property_exists($e, 'tags') && is_array($e->tags) ? $e->tags : [];

        return [
            'op' => $op,
            'key' => $key,
            'key_pattern' => $this->cacheKeyPattern($key),
            'store' => (string) ($e->storeName ?? ''),
            'ttl' => $ttl,
            'value_bytes' => $value !== null ? strlen(is_string($value) ? $value : serialize($value)) : 0,
            'tags' => implode(',', $tags),
            'trace_id' => $monitor->traceId,
            'timestamp' => round(microtime(true) * 1000),
        ];
    }

    /**
     * Collapse the volatile part of a cache key into a placeholder so keys of the
     * same shape group together — `products:42` → `products:{id}`, hex/uuid → {hash}.
     */
    protected function cacheKeyPattern(string $key): string
    {
        $parts = explode(':', $key);
        foreach ($parts as $i => $p) {
            if ($p === '') {
                continue;
            }
            if (preg_match('/^\d+$/', $p)) {
                $parts[$i] = '{id}';
            } elseif (preg_match('/^[0-9a-f]{16,}$/i', $p) || preg_match('/^[0-9a-f-]{32,}$/i', $p)) {
                $parts[$i] = '{hash}';
            }
        }

        return implode(':', $parts);
    }

    /** Header names whose values are masked before shipping. */
    protected const SENSITIVE_HEADERS = [
        'authorization', 'proxy-authorization', 'cookie', 'set-cookie',
        'x-api-key', 'x-observera-key', 'x-auth-token', 'stripe-signature',
    ];

    /**
     * Flatten + mask headers. Values for sensitive names (or any header whose
     * name hints at a secret) are redacted before leaving the app.
     *
     * @param  array<string, array<int, string>|string>  $headers
     * @return array<string, string>
     */
    protected function maskHeaders(array $headers): array
    {
        $out = [];

        foreach ($headers as $name => $values) {
            $value = is_array($values) ? implode(', ', $values) : (string) $values;
            $lower = strtolower((string) $name);

            $secret = in_array($lower, self::SENSITIVE_HEADERS, true)
                || preg_match('/(token|secret|api[-_]?key|password)/', $lower);

            $out[$name] = $secret ? $this->maskValue($value) : $value;
        }

        return $out;
    }

    protected function maskValue(string $value): string
    {
        // keep a scheme prefix (e.g. "Bearer") + a short tail, redact the middle
        if (preg_match('/^(\w+)\s+(.+)$/', $value, $m)) {
            return $m[1].' '.'••••••••'.'(masked)';
        }

        return '••••••••(masked)';
    }

    /**
     * Cap payloads so we never ship a huge body. Truncated bodies get a marker.
     *
     * Cutting is UTF-8 aware: `mb_strcut` trims to a byte budget WITHOUT splitting
     * a multibyte character (byte `substr` would corrupt Japanese/emoji etc., and
     * an invalid-UTF-8 tail makes json_encode fail on the whole envelope → lost
     * data). Any invalid bytes in the source are also sanitised for the same reason.
     */
    protected function capBody(string $body, ?int $max = null): string
    {
        $max ??= (int) config('observera.max_body', 65536);

        // Guarantee valid UTF-8 so the JSON envelope always encodes.
        if (! mb_check_encoding($body, 'UTF-8')) {
            $sub = mb_substitute_character();
            mb_substitute_character(0xFFFD);
            $body = mb_convert_encoding($body, 'UTF-8', 'UTF-8');
            mb_substitute_character($sub);
        }

        if (strlen($body) <= $max) {
            return $body;
        }

        return mb_strcut($body, 0, $max, 'UTF-8')."\n… (truncated ".strlen($body).' bytes)';
    }

    protected function recordException(LogShipper $shipper, RequestMonitor $monitor, \Throwable $ex): void
    {
        $request = request();

        $shipper->recordException([
            'class' => $ex::class,
            'message' => $ex->getMessage(),
            'file' => $ex->getFile(),
            'line' => $ex->getLine(),
            'stack' => $this->buildStack($ex),
            'context' => [
                'php_version' => PHP_VERSION,
                'server' => gethostname() ?: '',
            ],
            'trace_id' => $monitor->traceId,
            'method' => $request?->method(),
            'route' => $request?->route()?->uri() ? '/'.ltrim($request->route()->uri(), '/') : $request?->path(),
            'action' => $request?->route()?->getActionName(),
        ]);
    }

    /**
     * Structured stack frames with source snippets for app frames.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function buildStack(\Throwable $ex): array
    {
        $base = base_path();
        $frames = [];

        // the throw site first, then the call trace
        $raw = array_merge(
            [['file' => $ex->getFile(), 'line' => $ex->getLine(), 'function' => '', 'class' => '', 'type' => '']],
            $ex->getTrace(),
        );

        foreach (array_slice($raw, 0, 30) as $f) {
            $file = $f['file'] ?? '[internal]';
            $line = (int) ($f['line'] ?? 0);
            $vendor = str_contains($file, '/vendor/') || ! str_starts_with($file, $base);
            $fn = trim(($f['class'] ?? '').($f['type'] ?? '').($f['function'] ?? ''));

            $frame = [
                'function' => $fn !== '' ? $fn.'()' : $file,
                'file' => str_starts_with($file, $base) ? ltrim(substr($file, strlen($base)), '/') : $file,
                'line' => $line,
                'vendor' => $vendor,
            ];

            // source snippet for app frames only (±5 lines), bounded work
            if (! $vendor && $line > 0 && is_readable($file)) {
                $frame['code'] = $this->snippet($file, $line);
            }

            $frames[] = $frame;
        }

        return $frames;
    }

    /**
     * @return array{start: int, highlight: int, lines: array<int, string>}
     */
    protected function snippet(string $file, int $line): array
    {
        $all = @file($file, FILE_IGNORE_NEW_LINES) ?: [];
        $start = max(1, $line - 5);
        $end = min(count($all), $line + 4);
        $lines = [];
        for ($i = $start; $i <= $end; $i++) {
            $lines[$i] = $all[$i - 1] ?? '';
        }

        return ['start' => $start, 'highlight' => $line, 'lines' => $lines];
    }
}

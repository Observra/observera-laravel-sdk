<?php

namespace Observera\Laravel;

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Observera\Laravel\Console\InstallCommand;
use Observera\Laravel\Console\TestCommand;
use Observera\Laravel\Http\ObserveraMiddleware;
use Observera\Laravel\Instrumentation\Identity;
use Observera\Laravel\Instrumentation\RequestMonitor;
use Observera\Laravel\Transport\Client;

class ObserveraServiceProvider extends ServiceProvider
{
    /** Class of the job this worker is running, so jobs it dispatches know their origin. */
    protected string $currentJob = '';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/observera.php', 'observera');

        $this->app->singleton(RequestMonitor::class);
        $this->app->singleton(Identity::class);

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
                (string) ($config['queue_name'] ?? 'observera'),
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
        $identity = $this->app->make(Identity::class);

        // Keys whose values are masked in context / bodies / job metadata.
        $keys = implode('|', array_filter(array_map('trim', (array) ($config['redact_keys'] ?? []))));
        if ($keys !== '' && @preg_match('~(?:'.$keys.')~i', '') !== false) {
            LogShipper::$redactKeys = $keys;
        }

        // ---- logs (+ exceptions carried in log context) ----
        $this->app['events']->listen(MessageLogged::class, function (MessageLogged $e) use ($shipper, $monitor, $identity) {
            try {
                $context = $e->context ?? [];
                $context['trace_id'] ??= $monitor->traceId;
                $message = (string) $e->message;
                $ex = isset($context['exception']) && $context['exception'] instanceof \Throwable ? $context['exception'] : null;

                // A reported QueryException logs its message, bindings interpolated — ship the ? form
                if ($ex instanceof \Illuminate\Database\QueryException) {
                    $message = str_replace($ex->getMessage(), LogShipper::exceptionMessage($ex), $message);
                }

                $shipper->record($e->level, $message, $context, $identity->current());

                // Laravel reports exceptions as error logs with context.exception — split those out
                if ($ex) {
                    $this->recordException($shipper, $monitor, $identity, $ex);
                }
            } catch (\Throwable) {
                // never throw into the app's logger
            }
        });

        // ---- HTTP requests (timing + per-query telemetry) ----
        DB::listen(function ($query) use ($monitor) {
            try {
                $monitor->query($query->sql, (string) $query->connectionName, (float) $query->time);
            } catch (\Throwable) {
            }
        });

        try {
            $this->app->make(HttpKernel::class)->pushMiddleware(ObserveraMiddleware::class);
        } catch (\Throwable) {
            // console/queue contexts have no HTTP kernel — fine
        }

        // ---- outbound trace propagation (W3C traceparent) ----
        // A fresh span id per call; the http_out event reads it back off the
        // sent request, so the downstream request (parent_span_id) links to it.
        $http = $this->app->make(\Illuminate\Http\Client\Factory::class);
        if (($config['propagate_trace'] ?? true) && method_exists($http, 'globalRequestMiddleware')) {
            $deny = (array) ($config['propagate_trace_deny'] ?? []);
            $allow = (array) ($config['propagate_trace_hosts'] ?? []);
            $http->globalRequestMiddleware(function ($request) use ($monitor, $deny, $allow) {
                try {
                    $host = $request->getUri()->getHost();
                    if ($monitor->traceId === '' || $request->hasHeader('traceparent')
                        || ($allow !== [] && ! Str::is($allow, $host)) || ($deny !== [] && Str::is($deny, $host))) {
                        return $request;
                    }
                    $traced = $request->withHeader('traceparent', '00-'.$monitor->traceId.'-'.RequestMonitor::newSpanId().'-01');

                    return $monitor->traceState !== '' ? $traced->withHeader('tracestate', $monitor->traceState) : $traced;
                } catch (\Throwable) {
                    return $request; // header propagation must never fail the app's call
                }
            });
        }

        // ---- outbound HTTP calls ----
        $this->app['events']->listen(ResponseReceived::class, function (ResponseReceived $e) use ($shipper, $monitor, $identity) {
            try {
                $url = (string) $e->request->url();
                $host = parse_url($url, PHP_URL_HOST) ?: 'unknown';
                $path = parse_url($url, PHP_URL_PATH) ?: '/';
                $stats = method_exists($e->response, 'handlerStats') ? $e->response->handlerStats() : [];
                $duration = round((float) (($stats['total_time'] ?? 0) * 1000), 1);
                $respStream = $e->response->toPsrResponse()->getBody();

                $shipper->recordHttpOut([
                    'service' => $host,
                    'host' => $host,
                    'method' => $e->request->method(),
                    'url' => $path,
                    'status' => $e->response->status(),
                    'duration_ms' => $duration,
                    'bytes' => (int) ($respStream->getSize() ?? $e->response->header('Content-Length')),
                    'req_body' => $e->request->isMultipart()
                        ? '[multipart '.(int) $e->request->toPsrRequest()->getBody()->getSize().' bytes]'
                        : $this->captureBody($e->request->toPsrRequest()->getBody(), $e->request->header('Content-Type')[0] ?? ''),
                    'resp_body' => $this->captureBody($respStream, $e->response->header('Content-Type')),
                    'req_headers' => $this->maskHeaders($e->request->headers()),
                    'resp_headers' => $this->maskHeaders($e->response->headers()),
                    'trace_id' => $monitor->traceId,
                    'span_id' => $this->spanIdOf($e->request),
                    ...$this->userFields($identity),
                ]);

                // feed the span waterfall
                $monitor->httpCall($host, $e->request->method().' '.$host.$path, $duration);
            } catch (\Throwable) {
                // never break the app's HTTP call
            }
        });

        $this->app['events']->listen(ConnectionFailed::class, function (ConnectionFailed $e) use ($shipper, $monitor, $identity) {
            try {
                $url = (string) $e->request->url();
                $shipper->recordHttpOut([
                    'service' => parse_url($url, PHP_URL_HOST) ?: 'unknown',
                    'host' => parse_url($url, PHP_URL_HOST) ?: '',
                    'method' => $e->request->method(),
                    'url' => parse_url($url, PHP_URL_PATH) ?: '/',
                    'status' => 0,
                    'trace_id' => $monitor->traceId,
                    'span_id' => $this->spanIdOf($e->request),
                    ...$this->userFields($identity),
                ]);
            } catch (\Throwable) {
            }
        });

        // ---- queue jobs (run telemetry: duration, attempts, failures) ----
        // start times keyed by job id, set on JobProcessing and consumed on finish
        $jobStarts = [];
        $this->app['events']->listen(\Illuminate\Queue\Events\JobProcessing::class, function ($e) use (&$jobStarts, $identity, $monitor) {
            // long-lived worker: whoever was authenticated last job is not this one
            $identity->reset();

            if ($this->isOwnJob($e->job)) {
                return; // don't instrument our own ship job → no telemetry feedback loop
            }
            // Continue the dispatching request's trace, so the job's logs/HTTP
            // calls join it (request → job is one trace). No stamp → its own
            // trace, never the previous job's. Sync jobs run inside the
            // dispatching request and already share its trace — leave it be.
            // begin() also resets the query / http accumulators: a worker never
            // runs the HTTP middleware, so without it they grow for its lifetime.
            if ($e->connectionName !== 'sync') {
                $monitor->begin();
                $monitor->traceId = (string) ($e->job->payload()['observera']['trace_id'] ?? '') ?: $monitor->traceId;
            }
            $jobStarts[$e->job->getJobId() ?: spl_object_id($e->job)] = microtime(true);
            $this->currentJob = (string) $e->job->resolveName();
        });
        $this->app['events']->listen(\Illuminate\Queue\Events\JobProcessed::class, function ($e) use ($shipper, $monitor, &$jobStarts) {
            if ($this->isOwnJob($e->job)) {
                return;
            }
            $shipper->recordJob($this->jobEvent($e, 'ok', $jobStarts));
            $this->shipQueries($shipper, $monitor, $e->connectionName !== 'sync');
            $this->currentJob = '';
        });
        $this->app['events']->listen(\Illuminate\Queue\Events\JobFailed::class, function ($e) use ($shipper, $monitor, &$jobStarts) {
            if ($this->isOwnJob($e->job)) {
                return;
            }
            $this->currentJob = '';
            $ex = $e->exception ?? null;
            $shipper->recordJob($this->jobEvent($e, 'failed', $jobStarts, $ex
                ? $ex::class.' — '.LogShipper::exceptionMessage($ex)
                : ''));
            $this->shipQueries($shipper, $monitor, $e->connectionName !== 'sync');

            // Ship failures immediately: a queue:work process never fires the app's
            // terminating callbacks, so a buffered failure would otherwise wait for
            // batch_size or for the worker to exit (--max-time, up to an hour).
            $shipper->flush();
        });

        // Stamp every dispatched job with when it was queued and which request /
        // command / job queued it: gives a real queue wait time (dispatch → start)
        // and links the job row to the request trace that caused it.
        Queue::createPayloadUsing(function ($connection, $queue, $payload) use ($monitor) {
            if (($payload['displayName'] ?? '') === \Observera\Laravel\Jobs\ShipEnvelope::class) {
                return [];
            }

            return ['observera' => [
                'queued_at' => microtime(true),
                'trace_id' => $monitor->traceId,
                'from' => $this->dispatchOrigin(),
            ]];
        });

        // An attempt that threw and will be retried: Laravel fires the exception
        // first, then the release — keep the message for the `released` row.
        $jobErrors = [];
        $this->app['events']->listen(\Illuminate\Queue\Events\JobExceptionOccurred::class, function ($e) use (&$jobErrors) {
            if ($this->isOwnJob($e->job)) {
                return;
            }
            $jobErrors[$e->job->getJobId() ?: spl_object_id($e->job)] = $e->exception::class.' — '.LogShipper::exceptionMessage($e->exception);
        });
        $this->app['events']->listen(\Illuminate\Queue\Events\JobReleasedAfterException::class, function ($e) use ($shipper, $monitor, &$jobStarts, &$jobErrors) {
            if ($this->isOwnJob($e->job)) {
                return;
            }
            $key = $e->job->getJobId() ?: spl_object_id($e->job);
            $shipper->recordJob($this->jobEvent($e, 'released', $jobStarts, $jobErrors[$key] ?? ''));
            unset($jobErrors[$key]);
            $this->shipQueries($shipper, $monitor, $e->connectionName !== 'sync');
        });

        // Killed by its timeout. The worker exits right after this event, so ship
        // now. If the timeout also failed the job, JobFailed already recorded it.
        if (class_exists(\Illuminate\Queue\Events\JobTimedOut::class)) {
            $this->app['events']->listen(\Illuminate\Queue\Events\JobTimedOut::class, function ($e) use ($shipper, &$jobStarts) {
                if ($this->isOwnJob($e->job) || $e->job->hasFailed()) {
                    return;
                }
                $timeout = (int) ($e->job->payload()['timeout'] ?? 0);
                $shipper->recordJob($this->jobEvent($e, 'timed_out', $jobStarts, 'Job exceeded its timeout'.($timeout ? " of {$timeout}s" : '')));
                $shipper->flush();
            });
        }

        // `queue:retry` on a failed job.
        $this->app['events']->listen(\Illuminate\Queue\Events\JobRetryRequested::class, function ($e) use ($shipper) {
            $payload = method_exists($e, 'payload') ? $e->payload() : (json_decode((string) ($e->job->payload ?? ''), true) ?: []);
            $shipper->recordJob([
                'job_id' => (string) ($payload['uuid'] ?? $e->job->uuid ?? ''),
                'job_class' => (string) ($payload['displayName'] ?? 'UnknownJob'),
                'queue' => (string) ($e->job->queue ?? 'default'),
                'connection' => (string) ($e->job->connection ?? ''),
                'status' => 'retry_requested',
                'worker' => gethostname() ?: '',
                'timestamp' => round(microtime(true) * 1000),
            ]);
        });

        // A worker exiting — normal (max-time, restart signal) or not (memory,
        // timeout kill). Frequent stops mean a worker that keeps dying.
        $this->app['events']->listen(\Illuminate\Queue\Events\WorkerStopping::class, function ($e) use ($shipper) {
            $reason = $e->reason ?? null;
            $reason = $reason instanceof \BackedEnum ? (string) $reason->value : ($reason instanceof \UnitEnum ? $reason->name : '');
            $status = (int) ($e->status ?? 0);

            $shipper->record($status === 0 ? 'info' : 'warning', 'Queue worker stopped'.($reason !== '' ? ": {$reason}" : '')." (exit {$status})", [
                'observera' => 'worker_stopped',
                'exit_status' => $status,
                'reason' => $reason,
                'jobs_processed' => $e->jobsProcessed ?? null,
                'memory_mb' => isset($e->memoryUsage) ? round($e->memoryUsage / 1048576, 1) : round(memory_get_usage(true) / 1048576, 1),
                'worker' => gethostname() ?: '',
                'pid' => getmypid(),
            ]);
            $shipper->flush();
        });

        // ---- N+1: Eloquent lazy loading on a model fetched as part of a list ----
        // Laravel flags only models loaded in a multi-row result, so each hit is a
        // real N+1. The handler records instead of throwing and Laravel still
        // loads the relation — the app behaves exactly as before. Skipped when the
        // app already enabled preventLazyLoading itself (it wants the exception).
        // Installed once every provider has booted, so an AppServiceProvider that
        // calls preventLazyLoading() itself has already run and keeps its exception.
        $this->app->booted(function () use ($config, $shipper, $monitor, $identity) {
            if (! ($config['capture_lazy_loading'] ?? true) || Model::preventsLazyLoading()) {
                return;
            }
            $seen = [];
            $seenTrace = null;
            Model::preventLazyLoading();
            Model::handleLazyLoadingViolationUsing(function ($model, string $relation) use ($shipper, $monitor, $identity, &$seen, &$seenTrace) {
                if ($seenTrace !== $monitor->traceId) {
                    $seen = [];
                    $seenTrace = $monitor->traceId;
                }
                $key = $model::class.'::'.$relation;
                if (isset($seen[$key])) {
                    return; // once per request per relation — the loop would repeat it N times
                }
                $seen[$key] = true;

                $shipper->record('warning', "N+1 query: lazy loading {$key}", [
                    'observera' => 'lazy_loading',
                    'model' => $model::class,
                    'relation' => $relation,
                    'origin' => $this->dispatchOrigin(),
                    'trace_id' => $monitor->traceId,
                ], $identity->current());
            });
        });

        // ---- cache operations (hit/miss/write/forget) ----
        // Rows are capped per request (LogShipper::MAX_CACHE) and never trigger a flush.
        $cacheOps = [
            \Illuminate\Cache\Events\CacheHit::class => 'hit',
            \Illuminate\Cache\Events\CacheMissed::class => 'miss',
            \Illuminate\Cache\Events\KeyWritten::class => 'write',
            \Illuminate\Cache\Events\KeyForgotten::class => 'forget',
        ];
        foreach ($cacheOps as $event => $op) {
            $this->app['events']->listen($event, function ($e) use ($shipper, $monitor, $op) {
                try {
                    if (str_starts_with((string) ($e->key ?? ''), 'observera:')) {
                        return; // the SDK's own breaker state
                    }
                    $ttl = (int) ($e->seconds ?? 0);
                    $shipper->recordCache($this->cacheOp($op, $e, $monitor, $e->value ?? null, $op === 'write' ? ($ttl > 0 ? $ttl : -1) : 0));
                } catch (\Throwable) {
                    // never throw into Cache::get
                }
            });
        }

        // ---- scheduled tasks (cron) ----
        // schedule:work is long-lived too: each task is its own unit (fresh trace, empty accumulators).
        $this->app['events']->listen(\Illuminate\Console\Events\ScheduledTaskStarting::class, fn () => $monitor->begin());
        $this->app['events']->listen(\Illuminate\Console\Events\ScheduledTaskFinished::class, function ($e) use ($shipper, $monitor) {
            $exit = (int) ($e->task->exitCode ?? 0);
            $shipper->recordScheduled($this->scheduledRun($e->task, $exit === 0 ? 'ok' : 'failed', $monitor, round($e->runtime * 1000, 1), $exit));
            $this->shipQueries($shipper, $monitor);

            if ($exit !== 0) {
                $shipper->flush(); // same reason as job failures: schedule:work is long-lived
            }
        });
        $this->app['events']->listen(\Illuminate\Console\Events\ScheduledTaskFailed::class, function ($e) use ($shipper, $monitor) {
            $ex = $e->exception ?? null;
            $shipper->recordScheduled($this->scheduledRun($e->task, 'failed', $monitor, 0, 1,
                $ex ? $ex::class.' — '.LogShipper::exceptionMessage($ex) : ''));
            $this->shipQueries($shipper, $monitor);
            $shipper->flush();
        });
        $this->app['events']->listen(\Illuminate\Console\Events\ScheduledTaskSkipped::class, function ($e) use ($shipper, $monitor) {
            $shipper->recordScheduled($this->scheduledRun($e->task, 'skipped', $monitor, 0, 0));
        });

        // Flush at the end of the request/command/job (after the response is sent).
        $this->app->terminating(function () use ($shipper) {
            $shipper->deferred = false;
            $shipper->flush();
        });
        register_shutdown_function(fn () => $shipper->flush(force: true));
    }

    /**
     * Build a job-run event from a queue event. `$starts` is keyed by job id and
     * mutated (consumed) here so duration survives between JobProcessing and finish.
     *
     * @param  array<string, float>  $starts
     * @return array<string, mixed>
     */
    /** Span id from the `traceparent` actually sent on an outbound request ('' when none). */
    protected function spanIdOf(object $request): string
    {
        $tp = (string) ($request->header('traceparent')[0] ?? '');

        return preg_match('/^00-[0-9a-f]{32}-([0-9a-f]{16})-/', $tp, $m) ? $m[1] : '';
    }

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
        $stamp = $payload['observera'] ?? [];

        // Queue wait = dispatch → this worker picking it up, minus any requested
        // delay. First attempt only: a retry's payload still carries the original
        // stamp, so its "wait" would include the earlier runs and backoff.
        $waitMs = 0.0;
        if ($started && isset($stamp['queued_at']) && (int) $job->attempts() <= 1) {
            $waitMs = max(0.0, round(($started - (float) $stamp['queued_at']) * 1000 - (int) ($payload['delay'] ?? 0) * 1000, 1));
        }

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
            'payload' => $this->jobPayload($job, $payload),
            'exception' => $exception,
            'backoff' => (string) ($payload['backoff'] ?? ''),
            'timeout' => (int) ($payload['timeout'] ?? 0),
            'wait_ms' => $waitMs,
            // the trace the job ran on (= the stamp's, or the fresh one begin() gave it) —
            // its queries rows carry the same id
            'trace_id' => (string) ($stamp['trace_id'] ?? '') ?: $this->app->make(RequestMonitor::class)->traceId,
            'dispatched_from' => (string) ($stamp['from'] ?? ''),
            'timestamp' => round(microtime(true) * 1000),
        ];
    }

    /**
     * What a job row carries as `payload`. By default a metadata summary only:
     * the raw body holds the serialized command (constructor args — often
     * emails, tokens, whole models). capture_job_payload restores the raw body,
     * still redacted and capped.
     */
    protected function jobPayload(object $job, array $payload): string
    {
        if (config('observera.capture_job_payload', false)) {
            $raw = json_decode((string) $job->getRawBody(), true) ?: [];
            if (is_string($raw['data']['command'] ?? null)) {
                $raw['data']['command'] = LogShipper::redactText($raw['data']['command']); // serialized command
            }

            return LogShipper::capBody(Client::encode(LogShipper::redact($raw)), $this->maxBody());
        }

        return Client::encode(LogShipper::redact(array_intersect_key($payload, array_flip(['displayName', 'uuid', 'maxTries', 'timeout', 'backoff', 'delay', 'retryUntil']))));
    }

    /**
     * Queries recorded inside a job / scheduled task, shipped as `queries` rows on
     * its trace, then dropped from memory. Sync jobs run inside a request, whose
     * own terminate ships them.
     */
    protected function shipQueries(LogShipper $shipper, RequestMonitor $monitor, bool $own = true): void
    {
        if (! $own) {
            return;
        }
        $trace = $monitor->traceId;
        $shipper->recordQueries(array_map(fn ($q) => [
            'sql' => $q['sql'],
            'connection' => $q['connection'],
            'duration_ms' => $q['duration_ms'],
            'trace_id' => $trace,
        ], $monitor->queries));
        $monitor->queries = $monitor->httpCalls = [];
    }

    /**
     * The user an outbound call was made on behalf of, so a failing third-party
     * call says who it failed for.
     *
     * @return array{user_id: string, user_email: string, user_name: string}
     */
    protected function userFields(Identity $identity): array
    {
        $user = $identity->current();

        return ['user_id' => $user['id'], 'user_email' => $user['email'], 'user_name' => $user['name']];
    }

    /** Where a job is being dispatched from: the running job, the HTTP route, or the artisan command. */
    protected function dispatchOrigin(): string
    {
        if ($this->currentJob !== '') {
            return 'job '.$this->currentJob;
        }

        // Octane serves HTTP from a CLI process, so check for a routed request
        // before falling back to "artisan". Never let instrumentation throw.
        try {
            $request = $this->app->bound('request') ? $this->app['request'] : null;
            if ($request && ($route = $request->route())) {
                return $request->getMethod().' /'.ltrim($route->uri(), '/');
            }
        } catch (\Throwable) {
            // no usable request in this context
        }

        if ($this->app->runningInConsole()) {
            return trim('artisan '.($_SERVER['argv'][1] ?? ''));
        }

        return '';
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
        // Only the tail (max_output bytes): the end of a cron log is where it failed.
        if (is_string($file) && $file !== '' && $file !== '/dev/null' && is_readable($file)) {
            $output = LogShipper::redactText(self::tail($file, (int) config('observera.max_output', 65536)));
        }

        return [
            'task' => LogShipper::redactText((string) $name),
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

    /** Last $max bytes of a file (whole file when $max <= 0), UTF-8 safe, marked when cut. */
    public static function tail(string $file, int $max): string
    {
        $size = (int) @filesize($file);
        $fh = @fopen($file, 'rb');
        if (! $fh) {
            return '';
        }
        if ($max > 0 && $size > $max) {
            fseek($fh, -$max, SEEK_END);
        }
        $data = (string) stream_get_contents($fh);
        fclose($fh);

        if ($max > 0 && $size > $max) {
            $data = ltrim($data, "\x80..\xBF"); // drop a split leading character
            $data = '… (truncated — last '.strlen($data).' of '.$size." bytes)\n".$data;
        }

        return LogShipper::capBody($data, 0);
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

        $pattern = $this->cacheKeyPattern($key);

        // The raw key often embeds ids / emails / tokens: ship its shape plus a
        // short keyed hash (tells keys apart, cannot be reversed).
        return [
            'op' => $op,
            'key' => $pattern.'#'.Identity::hash($key, 12),
            'key_pattern' => $pattern,
            'store' => (string) ($e->storeName ?? ''),
            'ttl' => $ttl,
            'value_bytes' => $this->valueBytes($value),
            'tags' => implode(',', $tags),
            'trace_id' => $monitor->traceId,
            'timestamp' => round(microtime(true) * 1000),
        ];
    }

    /** Size of a cached value; 0 for objects (serializing a model can query / throw) or closures. */
    protected function valueBytes(mixed $value): int
    {
        try {
            return match (true) {
                $value === null, is_object($value) => 0,
                is_string($value) => strlen($value),
                default => strlen(serialize($value)),
            };
        } catch (\Throwable) {
            return 0;
        }
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

    /** Header names whose values are masked before shipping (any name containing one of these). */
    protected const SENSITIVE_HEADER = '/signature|auth|key|credential|session|jwt|subscription|token|secret|cookie|password/i';

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

            if ($lower === 'location') {
                $value = preg_replace('/\?.*$/s', '', $value); // signed redirect URLs carry tokens in the query
            }

            $out[$name] = preg_match(self::SENSITIVE_HEADER, $lower) ? $this->maskValue($value) : $value;
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

    protected function maxBody(): int
    {
        return (int) config('observera.max_body', 8192);
    }

    /**
     * Read an HTTP body for capture without disturbing it: only in-memory,
     * seekable streams (never a `stream` response or a `sink` file), at most
     * max_body bytes, position restored. Redacted, then capped.
     */
    protected function captureBody(\Psr\Http\Message\StreamInterface $stream, string $contentType): string
    {
        if (! config('observera.capture_bodies', true) || ! $stream->isSeekable()
            || ! in_array($stream->getMetadata('uri'), ['php://temp', 'php://memory'], true)) {
            return '';
        }

        $max = $this->maxBody();
        $pos = $stream->tell();
        $stream->rewind();
        $body = $max > 0 ? $stream->read($max) : $stream->getContents();
        $stream->seek($pos);

        $size = (int) ($stream->getSize() ?? strlen($body));
        $out = LogShipper::redactBody($body, $contentType, $max);

        return $size > strlen($body) && ! str_contains($out, '(truncated')
            ? $out."\n… (truncated — stored ".strlen($body).' of '.$size.' bytes)'
            : $out;
    }

    protected function recordException(LogShipper $shipper, RequestMonitor $monitor, Identity $identity, \Throwable $ex): void
    {
        $request = request();
        $user = $identity->current();

        $shipper->recordException([
            'class' => $ex::class,
            'message' => LogShipper::exceptionMessage($ex),
            'file' => $this->relativePath($ex->getFile()),
            'line' => $ex->getLine(),
            'stack' => $this->buildStack($ex),
            'context' => LogShipper::redact([
                'php_version' => PHP_VERSION,
                'server' => gethostname() ?: '',
            ]),
            'release' => (string) config('observera.release', ''),
            'trace_id' => $monitor->traceId,
            'user_id' => $user['id'],
            'user_email' => $user['email'],
            'user_name' => $user['name'],
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
        $snippets = (bool) config('observera.capture_source', true);

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

            $rel = $this->relativePath($file);
            $frame = [
                'function' => $fn !== '' ? $fn.'()' : $rel,
                'file' => $rel,
                'line' => $line,
                'vendor' => $vendor,
            ];

            // source snippet for app frames only (±5 lines), bounded work —
            // never config (secrets), compiled views or .env files
            if ($snippets && ! $vendor && $line > 0 && is_readable($file)
                && ! preg_match('~^(config/|storage/framework/views/)|(^|/)\.env~', $rel)) {
                $frame['code'] = $this->snippet($file, $line);
            }

            $frames[] = $frame;
        }

        return $frames;
    }

    /** Path relative to the app root (absolute paths reveal the server layout). */
    protected function relativePath(string $file): string
    {
        $base = rtrim(base_path(), '/').'/';

        return str_starts_with($file, $base) ? substr($file, strlen($base)) : $file;
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

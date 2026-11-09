<?php

namespace Observera\Laravel\Http;

use Closure;
use Illuminate\Http\Request;
use Observera\Laravel\Instrumentation\Identity;
use Observera\Laravel\Instrumentation\RequestMonitor;
use Observera\Laravel\LogShipper;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Times each HTTP request and, on terminate, ships the request event, the SQL
 * queries it ran, and a span waterfall (root + db + http spans). Runs after
 * the response is sent, so it adds no latency.
 */
class ObserveraMiddleware
{
    public function __construct(
        protected RequestMonitor $monitor,
        protected LogShipper $shipper,
        protected Identity $identity,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->monitor->begin();
        $this->identity->reset(); // Octane: this worker's previous request was someone else
        // Nothing ships mid-request: the batch goes out in terminate, after the
        // response has been sent (fastcgi_finish_request under FPM).
        $this->shipper->deferred = true;
        if (config('observera.trust_incoming_trace', true)) {
            $this->monitor->continueTrace($request->header('traceparent'), $request->header('tracestate'));
        }

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->shipper->deferred = false; // response is out: shipping may block now

        // Skip health checks / probes (container HEALTHCHECK, load balancers) —
        // otherwise they flood the request feed. Configurable via ignore_paths.
        $ignore = (array) config('observera.ignore_paths', []);
        if ($ignore !== [] && $request->is(...$ignore)) {
            return;
        }

        try {
            $this->report($request, $response);
        } catch (\Throwable) {
            // instrumentation must never break the app
        }
        $this->shipper->flush();
    }

    protected function report(Request $request, Response $response): void
    {
        $route = $request->route();
        $start = defined('LARAVEL_START') ? LARAVEL_START : $request->server('REQUEST_TIME_FLOAT');
        $duration = $start ? (microtime(true) - (float) $start) * 1000 : 0;
        $trace = $this->monitor->traceId;

        $routeUri = $route ? '/'.ltrim($route->uri(), '/') : '/'.ltrim($request->path(), '/');

        $status = $response->getStatusCode();
        $user = $this->identity->current();
        $userId = $user['id'];
        // same key release health already uses — carried on the request event too
        // so the server can group a visitor's requests into one session (replay)
        $sessionKey = $this->sessionKey($request, $userId);

        $this->shipper->recordRequest([
            'method' => $request->getMethod(),
            'route' => $routeUri,
            'controller_action' => $route?->getActionName() ?? '',
            'status' => $status,
            'duration_ms' => round($duration, 1),
            'db_ms' => round($this->monitor->dbTimeMs(), 1),
            'db_queries' => $this->monitor->dbQueries(),
            'queries_dropped' => $this->monitor->queriesDropped, // past the per-request cap; the API ignores it today
            'memory_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
            'trace_id' => $trace,
            'parent_span_id' => $this->monitor->parentSpanId,
            'user_id' => $userId,
            'user_email' => $user['email'],
            'user_name' => $user['name'],
            'session_key' => $sessionKey,
            'user_agent' => config('observera.send_pii', true) ? (string) $request->userAgent() : '',
            // same masking/redaction as outbound HTTP (third-party) capture
            'req_headers' => LogShipper::maskHeaders($request->headers->all()),
            'resp_headers' => LogShipper::maskHeaders($response->headers->all()),
            'req_body' => $this->requestBody($request),
            'resp_body' => $this->responseBody($response),
        ]);

        // ---- release health: one session touch per request ----
        // Session = web session id when present, else a stable per-user/client
        // key (server buckets these). Crashed = unhandled 5xx.
        $this->shipper->recordSession([
            'session_key' => $sessionKey,
            'distinct_id' => $userId !== ''
                ? 'user:'.$userId
                : 'anon:'.Identity::hash(($request->ip() ?? '').'|'.((string) $request->userAgent()), 16),
            'release' => (string) config('observera.release', ''),
            'status' => $status >= 500 ? 'crashed' : 'ok',
            'timestamp' => round(microtime(true) * 1000),
        ]);

        // per-query telemetry
        if ($this->monitor->queries) {
            $this->shipper->recordQueries(array_map(fn ($q) => [
                'sql' => $q['sql'],
                'connection' => $q['connection'],
                'duration_ms' => $q['duration_ms'],
                'trace_id' => $trace,
            ], $this->monitor->queries));
        }

        // span waterfall: root + db spans + http spans
        $spans = [[
            'kind' => 'app',
            'name' => $request->getMethod().' '.$routeUri,
            'start_offset_ms' => 0,
            'duration_ms' => round($duration, 1),
            'trace_id' => $trace,
            'span_id' => 'root',
            'parent_id' => '',
        ]];
        foreach ($this->monitor->queries as $q) {
            $spans[] = [
                'kind' => 'db',
                'name' => $this->summariseSql($q['sql']),
                'start_offset_ms' => $q['start_offset_ms'],
                'duration_ms' => $q['duration_ms'],
                'trace_id' => $trace,
                'parent_id' => 'root',
                'note' => $q['connection'],
            ];
        }
        foreach ($this->monitor->httpCalls as $h) {
            $spans[] = [
                'kind' => 'http',
                'name' => $h['name'],
                'start_offset_ms' => $h['start_offset_ms'],
                'duration_ms' => $h['duration_ms'],
                'trace_id' => $trace,
                'parent_id' => 'root',
                'note' => $h['note'],
            ];
        }
        $this->shipper->recordSpans($spans);
    }

    /**
     * Session key: the Laravel web session id when one is active (real user
     * session), otherwise a stable per-user (or per-client) key for stateless
     * API traffic. The server time-buckets the API keys into sessions.
     */
    protected function sessionKey(Request $request, string $userId): string
    {
        if ($request->hasSession() && $request->session()->isStarted()) {
            return 'web:'.Identity::hash($request->session()->getId()); // never the raw (replayable) id
        }
        $base = $userId !== '' ? $userId : ($request->ip() ?? '').'|'.((string) $request->userAgent());

        return 'api:'.Identity::hash($base, 24);
    }

    /**
     * Incoming payload, redacted (redact_keys) and capped (max_body). Uploaded
     * files are never read — only the plain form fields of a multipart post.
     * A body-less request (GET) records its query string instead.
     */
    protected function requestBody(Request $request): string
    {
        if (! config('observera.capture_bodies', true)) {
            return '';
        }

        $type = (string) $request->headers->get('Content-Type', '');
        $max = (int) config('observera.max_body', 8192);

        if (str_contains(strtolower($type), 'multipart/')) {
            return LogShipper::redactBody(json_encode($request->request->all()) ?: '', 'application/json', $max);
        }

        $body = (string) $request->getContent();

        if ($body === '' && ($query = (string) $request->server('QUERY_STRING', '')) !== '') {
            return LogShipper::redactBody($query, 'application/x-www-form-urlencoded', $max);
        }

        return LogShipper::redactBody($body, $type, $max);
    }

    /** Text responses only: streamed/file downloads and binary types are skipped. */
    protected function responseBody(Response $response): string
    {
        if (! config('observera.capture_bodies', true)
            || $response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            return '';
        }

        $type = (string) $response->headers->get('Content-Type', '');
        if ($type !== '' && ! preg_match('~json|xml|text/|javascript|x-www-form-urlencoded~i', $type)) {
            return '';
        }

        $body = $response->getContent();

        return is_string($body) ? LogShipper::redactBody($body, $type, (int) config('observera.max_body', 8192)) : '';
    }

    protected function summariseSql(string $sql): string
    {
        $sql = trim(preg_replace('/\s+/', ' ', $sql) ?? $sql);

        return mb_strlen($sql) > 80 ? mb_substr($sql, 0, 80).'…' : $sql;
    }
}

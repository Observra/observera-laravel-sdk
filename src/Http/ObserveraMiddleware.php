<?php

namespace Observera\Laravel\Http;

use Closure;
use Illuminate\Http\Request;
use Observera\Laravel\Instrumentation\RequestMonitor;
use Observera\Laravel\LogShipper;
use Symfony\Component\HttpFoundation\Response;

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
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->monitor->begin();

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        // Skip health checks / probes (container HEALTHCHECK, load balancers) —
        // otherwise they flood the request feed. Configurable via ignore_paths.
        $ignore = (array) config('observera.ignore_paths', []);
        if ($ignore !== [] && $request->is(...$ignore)) {
            return;
        }

        $route = $request->route();
        $start = defined('LARAVEL_START') ? LARAVEL_START : $request->server('REQUEST_TIME_FLOAT');
        $duration = $start ? (microtime(true) - (float) $start) * 1000 : 0;
        $trace = $this->monitor->traceId;

        $routeUri = $route ? '/'.ltrim($route->uri(), '/') : '/'.ltrim($request->path(), '/');

        $this->shipper->recordRequest([
            'method' => $request->getMethod(),
            'route' => $routeUri,
            'controller_action' => $route?->getActionName() ?? '',
            'status' => $response->getStatusCode(),
            'duration_ms' => round($duration, 1),
            'db_ms' => round($this->monitor->dbTimeMs(), 1),
            'db_queries' => $this->monitor->dbQueries(),
            'memory_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
            'trace_id' => $trace,
            'user_id' => (string) (optional($request->user())->getAuthIdentifier() ?? ''),
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

        $this->shipper->flush();
    }

    protected function summariseSql(string $sql): string
    {
        $sql = trim(preg_replace('/\s+/', ' ', $sql) ?? $sql);

        return mb_strlen($sql) > 80 ? mb_substr($sql, 0, 80).'…' : $sql;
    }
}

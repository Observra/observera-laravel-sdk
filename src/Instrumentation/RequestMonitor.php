<?php

namespace Observera\Laravel\Instrumentation;

/**
 * Per-request accumulator (singleton): correlation trace id, DB queries and
 * outbound HTTP calls gathered during the request (for query telemetry + the
 * trace waterfall).
 */
class RequestMonitor
{
    public string $traceId = '';

    public float $startedAt = 0.0;

    /** @var array<int, array<string, mixed>> */
    public array $queries = [];

    /** @var array<int, array<string, mixed>> */
    public array $httpCalls = [];

    public function begin(): void
    {
        $this->traceId = 'trace_'.bin2hex(random_bytes(8));
        $this->startedAt = microtime(true);
        $this->queries = [];
        $this->httpCalls = [];
    }

    public function query(string $sql, string $connection, float $timeMs): void
    {
        $this->queries[] = [
            'sql' => $sql,
            'connection' => $connection,
            'duration_ms' => round($timeMs, 2),
            'start_offset_ms' => round($this->offset() - $timeMs, 2),
        ];
    }

    public function httpCall(string $service, string $name, float $durationMs): void
    {
        $this->httpCalls[] = [
            'kind' => 'http',
            'name' => $name,
            'note' => $service,
            'duration_ms' => round($durationMs, 2),
            'start_offset_ms' => round($this->offset() - $durationMs, 2),
        ];
    }

    public function dbQueries(): int
    {
        return count($this->queries);
    }

    public function dbTimeMs(): float
    {
        return (float) array_sum(array_column($this->queries, 'duration_ms'));
    }

    protected function offset(): float
    {
        return $this->startedAt ? (microtime(true) - $this->startedAt) * 1000 : 0.0;
    }
}

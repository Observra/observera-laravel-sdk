<?php

namespace Observera\Laravel\Instrumentation;

/**
 * Per-request accumulator (singleton): correlation trace id, DB queries and
 * outbound HTTP calls gathered during the request (for query telemetry + the
 * trace waterfall).
 */
class RequestMonitor
{
    /** W3C trace-id: 32 lowercase hex, so it can be propagated as `traceparent`. */
    public string $traceId = '';

    /** Span id of the upstream caller when the trace was continued from `traceparent`. */
    public string $parentSpanId = '';

    /** Incoming `tracestate`, passed through verbatim on outbound calls. */
    public string $traceState = '';

    public float $startedAt = 0.0;

    /** @var array<int, array<string, mixed>> */
    public array $queries = [];

    /** @var array<int, array<string, mixed>> */
    public array $httpCalls = [];

    /** Per-unit-of-work cap on kept queries / http calls (bounded memory in workers). */
    public const MAX_ENTRIES = 500;

    /** Queries past the cap: counted (and timed) but not kept. */
    public int $queriesDropped = 0;

    public float $droppedQueryMs = 0.0;

    public function begin(): void
    {
        $this->traceId = self::newTraceId();
        $this->parentSpanId = '';
        $this->traceState = '';
        $this->startedAt = microtime(true);
        $this->queries = [];
        $this->httpCalls = [];
        $this->queriesDropped = 0;
        $this->droppedQueryMs = 0.0;
    }

    /**
     * Continue an upstream trace from a W3C `traceparent`
     * (00-<32 hex trace-id>-<16 hex parent-id>-<2 hex flags>). Invalid or
     * all-zero ids are ignored per spec — the fresh trace from begin() stays.
     */
    public function continueTrace(?string $traceparent, ?string $tracestate = null): bool
    {
        if (! $traceparent || ! preg_match('/^00-([0-9a-f]{32})-([0-9a-f]{16})-[0-9a-f]{2}$/', strtolower(trim($traceparent)), $m)
            || $m[1] === str_repeat('0', 32) || $m[2] === str_repeat('0', 16)) {
            return false;
        }

        $this->traceId = $m[1];
        $this->parentSpanId = $m[2];
        $this->traceState = self::validTraceState((string) $tracestate) ? trim((string) $tracestate) : '';

        return true;
    }

    /**
     * W3C tracestate: ≤ 512 chars, ≤ 32 comma-separated list members, each
     * `key=value` (key: lowercase [a-z0-9_*-/], optional tenant@system; value:
     * printable ASCII without `,` or `=`). Anything else is dropped, not forwarded.
     */
    public static function validTraceState(string $state): bool
    {
        $state = trim($state);
        if ($state === '' || strlen($state) > 512) {
            return false;
        }
        $members = array_filter(array_map('trim', explode(',', $state)), fn ($m) => $m !== '');
        if ($members === [] || count($members) > 32) {
            return false;
        }
        foreach ($members as $m) {
            if (! preg_match('~^(?:[a-z][a-z0-9_\-*/]{0,255}|[a-z0-9][a-z0-9_\-*/]{0,240}@[a-z][a-z0-9_\-*/]{0,13})=[\x20-\x2b\x2d-\x3c\x3e-\x7e]{0,255}[\x21-\x2b\x2d-\x3c\x3e-\x7e]$~', $m)) {
                return false;
            }
        }

        return true;
    }

    public static function newTraceId(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function newSpanId(): string
    {
        return bin2hex(random_bytes(8));
    }

    public function query(string $sql, string $connection, float $timeMs): void
    {
        if (count($this->queries) >= self::MAX_ENTRIES) {
            $this->queriesDropped++;
            $this->droppedQueryMs += $timeMs;

            return;
        }
        $this->queries[] = [
            'sql' => self::stripLiterals($sql),
            'connection' => $connection,
            'duration_ms' => round($timeMs, 2),
            'start_offset_ms' => round($this->offset() - $timeMs, 2),
        ];
    }

    /**
     * Replace literals in raw SQL with `?`: whereRaw / DB::statement can embed
     * values (emails, tokens) straight in the SQL text. Strings ('…', with ''
     * escapes) and bare numbers; identifiers like t1.col are left alone.
     */
    public static function stripLiterals(string $sql): string
    {
        $sql = preg_replace("~'(?:[^'\\\\]|''|\\\\.)*'~s", '?', $sql) ?? $sql;

        return preg_replace('~(?<![\w.$"`?])-?\d+(?:\.\d+)?\b~', '?', $sql) ?? $sql;
    }

    public function httpCall(string $service, string $name, float $durationMs): void
    {
        if (count($this->httpCalls) >= self::MAX_ENTRIES) {
            return;
        }
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
        return count($this->queries) + $this->queriesDropped;
    }

    public function dbTimeMs(): float
    {
        return (float) array_sum(array_column($this->queries, 'duration_ms')) + $this->droppedQueryMs;
    }

    protected function offset(): float
    {
        return $this->startedAt ? (microtime(true) - $this->startedAt) * 1000 : 0.0;
    }
}

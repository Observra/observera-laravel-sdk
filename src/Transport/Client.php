<?php

namespace Observera\Laravel\Transport;

use GuzzleHttp\Client as Guzzle;

/**
 * Best-effort HTTP shipper to Observera's /intake/events. Uses Guzzle directly
 * (not the host's Http client) so shipping can't recurse through the log
 * listener, and swallows every error — telemetry must never break the app.
 *
 * Circuit breaker: a failed send (exception/timeout, HTTP 5xx or 429) opens
 * the breaker for a cool-down (30s, doubling up to 5 min; 429 honours
 * Retry-After). While open, batches are dropped without touching the network,
 * so a dead ingest costs one timeout per window, not one per request. State
 * lives in the app cache (shared across FPM workers) with a static fallback.
 */
class Client
{
    protected Guzzle $http;

    /** Plain http is refused except for local development hosts. */
    protected bool $insecure = false;

    public const BREAKER_KEY = 'observera:breaker';

    /** @var array{until: float, failures: int}|null in-process copy / fallback when no cache */
    protected static ?array $breaker = null;

    protected static bool $warnedInsecure = false;

    public function __construct(
        protected string $endpoint,
        protected string $key,
        float $timeout = 2.0,
        float $connectTimeout = 1.0,
    ) {
        $this->insecure = ! self::endpointAllowed($endpoint);
        $this->http = new Guzzle([
            'base_uri' => rtrim($endpoint, '/').'/',
            'timeout' => $timeout,
            'connect_timeout' => $connectTimeout,
            'http_errors' => false,
            'allow_redirects' => false, // never replay the key to wherever a 3xx points
            'verify' => true,
        ]);
    }

    /** https anywhere; http only for localhost / 127.0.0.1 / ::1 / *.test / *.local. */
    public static function endpointAllowed(string $endpoint): bool
    {
        $scheme = strtolower((string) parse_url($endpoint, PHP_URL_SCHEME));
        $host = strtolower(trim((string) parse_url($endpoint, PHP_URL_HOST), '[]'));

        return $scheme === 'https' || ($scheme === 'http' && (
            in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            || str_ends_with($host, '.test') || str_ends_with($host, '.local')
        ));
    }

    /** JSON that never fails on one bad string: invalid UTF-8 substituted, unencodable parts nulled. */
    public static function encode(mixed $data): string
    {
        return (string) json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Best-effort fire-and-forget used by the log listener.
     *
     * @param  array<int, array<string, mixed>>  $logs
     */
    public function sendLogs(array $logs): void
    {
        $this->post($logs); // result ignored — shipping must never break the app
    }

    /**
     * Best-effort send of a full intake envelope ({logs?, requests?, ...}).
     */
    public function sendEnvelope(array $envelope): void
    {
        $this->send($envelope);
    }

    /** Send one envelope through the breaker. False = not delivered (dropped or failed). */
    public function send(array $envelope): bool
    {
        if ($envelope === [] || $this->key === '' || $this->refuseInsecure() || self::breakerOpen()) {
            return false;
        }

        try {
            $res = $this->http->post('intake/events', [
                'headers' => $this->headers(),
                'body' => self::encode($envelope),
            ]);
            $status = $res->getStatusCode();
            if ($status >= 500 || $status === 429) {
                self::tripBreaker($status === 429 ? self::retryAfter($res->getHeaderLine('Retry-After')) : null);

                return false;
            }
            self::resetBreaker();

            return $status < 300;
        } catch (\Throwable) {
            // never propagate — telemetry must not break the app
            self::tripBreaker();

            return false;
        }
    }

    /**
     * Send a batch and report the outcome (used by `observera:test`).
     * http_errors is off, so 4xx/5xx come back as a status, not an exception;
     * only transport failures (DNS/connect) throw and are caught. Bypasses the
     * breaker on purpose: a connection test must actually try.
     *
     * @param  array<int, array<string, mixed>>  $logs
     * @return array{ok: bool, status: int, error: ?string}
     */
    public function post(array $logs): array
    {
        if ($this->key === '') {
            return ['ok' => false, 'status' => 0, 'error' => 'OBSERVERA_KEY is not set'];
        }
        if ($logs === []) {
            return ['ok' => false, 'status' => 0, 'error' => 'no logs to send'];
        }
        if ($this->insecure) {
            return ['ok' => false, 'status' => 0, 'error' => 'OBSERVERA_ENDPOINT must be https (plain http is allowed only for localhost/*.test/*.local)'];
        }

        try {
            $res = $this->http->post('intake/events', [
                'headers' => $this->headers(),
                'body' => self::encode(['logs' => $logs]),
            ]);

            $status = $res->getStatusCode();

            return [
                'ok' => $status >= 200 && $status < 300,
                'status' => $status,
                'error' => $status < 300 ? null : trim((string) $res->getBody()),
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'status' => 0, 'error' => $e->getMessage()];
        }
    }

    protected function headers(): array
    {
        return [
            'x-observera-key' => $this->key,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    protected function refuseInsecure(): bool
    {
        if ($this->insecure && ! self::$warnedInsecure) {
            self::$warnedInsecure = true;
            error_log('[observera] refusing to ship telemetry over plain http to '.parse_url($this->endpoint, PHP_URL_HOST).'; set OBSERVERA_ENDPOINT to an https URL');
        }

        return $this->insecure;
    }

    // ---- circuit breaker ----

    public static function breakerOpen(): bool
    {
        $state = self::breakerState();

        return $state !== null && $state['until'] > microtime(true);
    }

    /** Open the breaker: 30s × 2^(failures-1), max 5 min — or the server's Retry-After. */
    public static function tripBreaker(?int $retryAfter = null): void
    {
        $failures = (int) (self::breakerState()['failures'] ?? 0) + 1;
        $cooldown = $retryAfter ?? min(300, 30 * 2 ** min($failures - 1, 4));
        self::$breaker = ['until' => microtime(true) + $cooldown, 'failures' => $failures];
        self::cache(fn ($c) => $c->put(self::BREAKER_KEY, self::$breaker, $cooldown + 600));
    }

    public static function resetBreaker(): void
    {
        if (self::$breaker === null) {
            return; // healthy already: no cache write per send
        }
        self::$breaker = null;
        self::cache(fn ($c) => $c->forget(self::BREAKER_KEY));
    }

    /** @return array{until: float, failures: int}|null */
    public static function breakerState(): ?array
    {
        $shared = self::cache(fn ($c) => $c->get(self::BREAKER_KEY), false);
        if ($shared !== false) {
            self::$breaker = is_array($shared) ? $shared : null; // another worker may have tripped/reset it
        }

        return self::$breaker;
    }

    /** Retry-After as seconds (delta or HTTP date), capped at 1h; null when absent. */
    protected static function retryAfter(string $value): ?int
    {
        if ($value === '') {
            return null;
        }
        $seconds = ctype_digit($value) ? (int) $value : (($t = strtotime($value)) ? $t - time() : 0);

        return $seconds > 0 ? min($seconds, 3600) : null;
    }

    /** Run against the app's cache store; $fallback when there is no app / cache (or it fails). */
    protected static function cache(callable $fn, mixed $fallback = null): mixed
    {
        try {
            if (function_exists('app') && app()->bound('cache')) {
                return $fn(app('cache')->store());
            }
        } catch (\Throwable) {
            // cache down: the static copy carries the state for this process
        }

        return $fallback;
    }
}

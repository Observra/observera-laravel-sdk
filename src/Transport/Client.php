<?php

namespace Observera\Laravel\Transport;

use GuzzleHttp\Client as Guzzle;

/**
 * Best-effort HTTP shipper to Observera's /intake/events. Uses Guzzle directly
 * (not the host's Http client) so shipping can't recurse through the log
 * listener, and swallows every error — telemetry must never break the app.
 */
class Client
{
    protected Guzzle $http;

    public function __construct(
        protected string $endpoint,
        protected string $key,
        float $timeout = 2.0,
        float $connectTimeout = 1.0,
    ) {
        $this->http = new Guzzle([
            'base_uri' => rtrim($endpoint, '/').'/',
            'timeout' => $timeout,
            'connect_timeout' => $connectTimeout,
            'http_errors' => false,
        ]);
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
        if ($envelope === [] || $this->key === '') {
            return;
        }

        try {
            $this->http->post('intake/events', [
                'headers' => [
                    'x-observera-key' => $this->key,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'json' => $envelope,
            ]);
        } catch (\Throwable) {
            // never propagate — telemetry must not break the app
        }
    }

    /**
     * Send a batch and report the outcome (used by `observera:test`).
     * http_errors is off, so 4xx/5xx come back as a status, not an exception;
     * only transport failures (DNS/connect) throw and are caught.
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

        try {
            $res = $this->http->post('intake/events', [
                'headers' => [
                    'x-observera-key' => $this->key,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'json' => ['logs' => $logs],
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
}

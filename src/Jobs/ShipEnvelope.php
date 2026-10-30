<?php

namespace Observera\Laravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Observera\Laravel\Transport\Client;

/**
 * Ships a telemetry envelope off the request/worker thread. Dispatched by
 * LogShipper when OBSERVERA_QUEUE is set, so the blocking HTTP POST never
 * happens inline with a web request.
 *
 * Encrypted at rest (the envelope holds logs/bodies). Best-effort by design:
 * a failed send is dropped (the breaker opens), and a worker that dies
 * mid-send retries briefly, then the batch is discarded — never parked in
 * failed_jobs, never noisy. The circuit breaker drops it outright while the
 * ingest endpoint is failing.
 */
class ShipEnvelope implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 10;

    public bool $failOnTimeout = false;

    /** @param array<string, mixed> $envelope */
    public function __construct(protected array $envelope) {}

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [5, 15];
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(10);
    }

    public function handle(): void
    {
        $config = config('observera');

        if (empty($config['key']) || $this->envelope === [] || Client::breakerOpen()) {
            return; // breaker open → drop the batch
        }

        // Never throws: a failed send trips the breaker and the batch is dropped.
        // tries/backoff only cover a worker dying mid-send (timeout, crash).
        (new Client(
            $config['endpoint'],
            (string) $config['key'],
            (float) $config['timeout'],
            (float) $config['connect_timeout'],
        ))->sendEnvelope($this->envelope);
    }

    /** Discarded batches are not an app failure: nothing to report. */
    public function failed(?\Throwable $e = null): void {}
}

<?php

namespace Observera\Laravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Observera\Laravel\Transport\Client;

/**
 * Ships a telemetry envelope off the request/worker thread. Dispatched by
 * LogShipper when OBSERVERA_QUEUE is set, so the blocking HTTP POST never
 * happens inline with a web request.
 */
class ShipEnvelope implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    /** @param array<string, mixed> $envelope */
    public function __construct(protected array $envelope) {}

    public function handle(): void
    {
        $config = config('observera');

        if (empty($config['key']) || $this->envelope === []) {
            return;
        }

        (new Client(
            $config['endpoint'],
            (string) $config['key'],
            (float) $config['timeout'],
            (float) $config['connect_timeout'],
        ))->sendEnvelope($this->envelope);
    }
}

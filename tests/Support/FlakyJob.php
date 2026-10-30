<?php

namespace Observera\Laravel\Tests\Support;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class FlakyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 2;

    public function handle(): void
    {
        if ($this->attempts() === 1) {
            throw new \RuntimeException('flaky first attempt');
        }
    }
}

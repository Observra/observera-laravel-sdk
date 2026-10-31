<?php

namespace Observera\Laravel\Tests\Support;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Constructor args that must never leave the app in a job row. */
class SecretJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public string $email = 'leak@shop.test', public string $password = 'hunter2') {}

    public function handle(): void {}
}

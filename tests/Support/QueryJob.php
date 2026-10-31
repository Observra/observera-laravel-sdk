<?php

namespace Observera\Laravel\Tests\Support;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

/** Runs more queries than RequestMonitor::MAX_ENTRIES. */
class QueryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function handle(): void
    {
        for ($i = 0; $i < 600; $i++) {
            DB::select('select ? as n', [$i]);
        }
    }
}

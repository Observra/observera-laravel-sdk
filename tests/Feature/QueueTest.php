<?php

namespace Observera\Laravel\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Observera\Laravel\Instrumentation\RequestMonitor;
use Observera\Laravel\Tests\Support\FlakyJob;
use Observera\Laravel\Tests\Support\OkJob;
use Observera\Laravel\Tests\TestCase;

/** Queue job stamping, wait time, trace linking and retries. */
class QueueTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createQueueTables();
    }

    public function test_job_dispatched_from_a_route_is_stamped_and_linked_to_its_trace(): void
    {
        $this->app->make(RequestMonitor::class)->traceId = 'trace-from-request';
        Route::get('/orders/{id}', fn () => 'ok');
        $req = Request::create('/orders/5', 'GET');
        $req->setRouteResolver(fn () => Route::getRoutes()->match($req));
        $this->app->instance('request', $req);

        OkJob::dispatch();

        $payload = json_decode(DB::table('jobs')->value('payload'), true);
        $this->assertTrue(isset($payload['observera']['queued_at']), 'payload carries observera stamp');
        $this->assertSame('GET /orders/{id}', $payload['observera']['from'] ?? '', 'dispatch origin is the route');

        $this->app->instance('request', Request::create('/')); // console-like: a request with no route
        usleep(150000); // ~150ms in the queue
        Artisan::call('queue:work', ['--stop-when-empty' => true, '--sleep' => 0]);

        $ok = collect($this->sent('jobs'))->firstWhere('job_class', OkJob::class);
        $this->assertGreaterThanOrEqual(150, $ok['wait_ms'] ?? 0, 'job row has wait_ms >= 150');
        $this->assertSame('trace-from-request', $ok['trace_id'] ?? '', 'job row linked to dispatching trace');
        $this->assertSame('GET /orders/{id}', $ok['dispatched_from'] ?? '', 'job row has dispatched_from');

        $stop = collect($this->sent('logs'))->first(fn ($l) => str_contains($l['message'] ?? '', 'Queue worker stopped'));
        $this->assertNotNull($stop, 'worker stop reported');
    }

    public function test_job_released_after_exception_then_succeeds_on_retry(): void
    {
        FlakyJob::dispatch();
        Artisan::call('queue:work', ['--once' => true, '--sleep' => 0]);
        Artisan::call('queue:work', ['--once' => true, '--sleep' => 0]);

        $jobs = collect($this->sent('jobs'))->where('job_class', FlakyJob::class)->values();
        $this->assertSame('released', $jobs[0]['status'] ?? '', 'released row');
        $this->assertStringContainsString('flaky first attempt', $jobs[0]['exception'] ?? '', 'released row carries the exception');
        $this->assertSame('ok', $jobs[1]['status'] ?? '', 'retry succeeds as ok');
        $this->assertSame(2, $jobs[1]['attempts'] ?? 0, 'retry is attempt 2');
        $this->assertEquals(0, $jobs[1]['wait_ms'] ?? -1, 'retry has no wait_ms');
    }
}

<?php

namespace Observera\Laravel\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Observera\Laravel\Instrumentation\RequestMonitor;
use Observera\Laravel\Tests\Support\TracedJob;
use Observera\Laravel\Tests\TestCase;

/** Port of tests/trace_check.php: W3C traceparent in, out, across the queue; deny-list respected. */
class TracingTest extends TestCase
{
    private const TID = '4bf92f3577b34da6a3ce929d0e0e4736';

    private const PARENT = '00f067aa0ba902b7';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('observera.propagate_trace_deny', ['denied.test', '*.private.test']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->createQueueTables();

        Http::fake(['*' => Http::response('{"ok":true}', 200)]);
        Route::get('/checkout', function () {
            Http::get('http://inventory.test/stock');
            Http::get('http://denied.test/x');
            Http::get('http://api.private.test/y');
            TracedJob::dispatch();

            return 'ok';
        });
    }

    private function sentHeader(string $host, string $name): string
    {
        $req = Http::recorded(fn ($r) => parse_url($r->url(), PHP_URL_HOST) === $host)->last()[0] ?? null;

        return (string) ($req?->header($name)[0] ?? '');
    }

    private function checkout(string $traceparent, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->hit('GET', '/checkout', ['HTTP_TRACEPARENT' => $traceparent] + $extra);
    }

    public function test_incoming_trace_is_continued_and_propagated(): void
    {
        $resp = $this->checkout('00-'.self::TID.'-'.self::PARENT.'-01', ['HTTP_TRACESTATE' => 'vendor=abc']);
        $this->assertSame(200, $resp->getStatusCode(), 'route ran');

        // 1. incoming traceparent is continued
        $req = collect($this->sent('requests'))->last();
        $this->assertSame(self::TID, $req['trace_id'] ?? '', 'request continues incoming trace-id');
        $this->assertSame(self::PARENT, $req['parent_span_id'] ?? '', 'request records parent_span_id');

        // 2. outbound call carries traceparent with the same trace + a fresh span = http_out.span_id
        $tp = $this->sentHeader('inventory.test', 'traceparent');
        $this->assertMatchesRegularExpression('/^00-([0-9a-f]{32})-([0-9a-f]{16})-01$/', $tp);
        preg_match('/^00-([0-9a-f]{32})-([0-9a-f]{16})-01$/', $tp, $m);
        $this->assertSame(self::TID, $m[1], 'outbound traceparent has same trace-id');
        $this->assertNotSame(self::PARENT, $m[2], 'outbound span id is new (not the parent)');
        $this->assertSame('vendor=abc', $this->sentHeader('inventory.test', 'tracestate'), 'tracestate passed through');
        $out = collect($this->sent('http_out'))->firstWhere('host', 'inventory.test');
        $this->assertSame($m[2], $out['span_id'] ?? '', 'http_out span_id equals sent span id');

        // 3. deny-list (exact + wildcard)
        $this->assertSame('', $this->sentHeader('denied.test', 'traceparent'), 'denied host gets no traceparent');
        $this->assertSame('', $this->sentHeader('api.private.test', 'traceparent'), 'wildcard-denied host gets no traceparent');
        $this->assertSame('', collect($this->sent('http_out'))->firstWhere('host', 'denied.test')['span_id'] ?? 'x', 'denied http_out has empty span_id');
    }

    public function test_queued_job_continues_the_dispatching_request_trace(): void
    {
        $this->checkout('00-'.self::TID.'-'.self::PARENT.'-01');

        $this->app->instance('request', Request::create('/'));
        $this->app->make(RequestMonitor::class)->traceId = 'worker-stale';
        Artisan::call('queue:work', ['--stop-when-empty' => true, '--sleep' => 0]);

        $log = collect($this->sent('logs'))->first(fn ($l) => ($l['message'] ?? '') === 'inside traced job');
        $this->assertSame(self::TID, $log['trace_id'] ?? '', 'job log continues request trace');
        $job = collect($this->sent('jobs'))->firstWhere('job_class', TracedJob::class);
        $this->assertSame(self::TID, $job['trace_id'] ?? '', 'job row on the same trace');
    }

    public function test_invalid_incoming_header_is_ignored_and_a_fresh_trace_is_used(): void
    {
        $this->checkout('00-'.str_repeat('0', 32).'-'.self::PARENT.'-01');

        $req = collect($this->sent('requests'))->last();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $req['trace_id'] ?? '', 'fresh trace id is 32 hex');
        $this->assertSame('', $req['parent_span_id'] ?? 'x', 'all-zero traceparent ignored (no parent)');
        $this->assertNotSame(str_repeat('0', 32), $req['trace_id'] ?? '', 'all-zero trace id not adopted');
        $this->assertStringContainsString($req['trace_id'] ?? '-', $this->sentHeader('inventory.test', 'traceparent'), 'outbound uses the fresh trace');
    }
}

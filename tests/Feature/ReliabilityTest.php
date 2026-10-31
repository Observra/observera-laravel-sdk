<?php

namespace Observera\Laravel\Tests\Feature;

use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Response as PsrResponse;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\LazyLoadingViolationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Observera\Laravel\Instrumentation\RequestMonitor;
use Observera\Laravel\Jobs\ShipEnvelope;
use Observera\Laravel\LogShipper;
use Observera\Laravel\ObserveraServiceProvider;
use Observera\Laravel\Tests\Support\AppLikeProvider;
use Observera\Laravel\Tests\Support\HPost;
use Observera\Laravel\Tests\Support\HUser;
use Observera\Laravel\Tests\Support\QueryJob;
use Observera\Laravel\Tests\TestCase;
use Observera\Laravel\Transport\Client;

/**
 * The SDK's impact on the host app, with REAL shipping: a local `php -S`
 * capture server plays the ingest (/ok records envelopes, /slow sleeps 2s,
 * /e500, /e429 + Retry-After, /redirect). Circuit breaker, shipping after the
 * response, bounded worker memory, invalid UTF-8, lazy-loading ownership,
 * stream-safe body capture, scheduled output tail, trace header hygiene,
 * cache rows never forcing a flush.
 */
class ReliabilityTest extends TestCase
{
    private const KEY = 'obs_test_harness';

    protected bool $fakeTransport = false;

    private static ?string $dir = null;

    private static int $port = 0;

    /** @var resource|null */
    private static $server = null;

    private static ?string $serverError = null;

    protected LogShipper $shipper;

    protected RequestMonitor $monitor;

    public static function setUpBeforeClass(): void
    {
        $dir = sys_get_temp_dir().'/observera-sdk-reliability-'.getmypid();
        @mkdir($dir, 0777, true);
        self::$dir = $dir = realpath($dir);
        file_put_contents("$dir/router.php", <<<'PHP'
            <?php
            $p = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
            file_put_contents(__DIR__.'/hits.log', strtok(substr($p, 1), '/')."\n", FILE_APPEND | LOCK_EX);
            if (str_starts_with($p, '/slow')) { sleep(2); echo '{}'; return; }
            if (str_starts_with($p, '/e500')) { http_response_code(500); return; }
            if (str_starts_with($p, '/e429')) { http_response_code(429); header('Retry-After: 120'); return; }
            if (str_starts_with($p, '/redirect')) { header('Location: /ok/intake/events', true, 307); return; }
            file_put_contents(__DIR__.'/bodies.log', file_get_contents('php://input')."\n", FILE_APPEND | LOCK_EX);
            echo '{"ok":true}';
            PHP);

        // a free port: bind :0, read it back, release it
        $probe = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($probe === false) {
            self::$serverError = "cannot bind a local port: $errstr";

            return;
        }
        self::$port = (int) substr(strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);

        // workers: /slow holds one for 2s; the next tests must not queue behind it
        $env = ['PHP_CLI_SERVER_WORKERS' => '4'] + getenv();
        self::$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:'.self::$port, "$dir/router.php"], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $dir, $env) ?: null;
        for ($i = 0; $i < 50 && self::$server; $i++) {
            if ($sock = @fsockopen('127.0.0.1', self::$port, $errno, $errstr, 0.1)) {
                fclose($sock);

                return;
            }
            usleep(100000);
        }
        self::$serverError = 'php -S did not start on 127.0.0.1:'.self::$port;
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server) {
            proc_terminate(self::$server);
            proc_close(self::$server);
            self::$server = null;
        }
        if (self::$dir) {
            exec('pkill -f '.escapeshellarg(self::$dir.'/router.php')); // stray cli-server workers
            exec('rm -rf '.escapeshellarg(self::$dir));
        }
    }

    protected function getPackageProviders($app): array
    {
        return [ObserveraServiceProvider::class, AppLikeProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set([
            'observera.endpoint' => $this->url('ok'),
            'observera.timeout' => 0.5,
            'observera.connect_timeout' => 0.5,
            'observera.propagate_trace_hosts' => ['down.test', '*.allowed.test'],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->createQueueTables();
        Schema::create('users', function ($t) { $t->id(); $t->string('name'); });
        Schema::create('posts', function ($t) { $t->id(); $t->unsignedBigInteger('user_id'); $t->string('title'); });
        $this->shipper = $this->app->make(LogShipper::class);
        $this->monitor = $this->app->make(RequestMonitor::class);
        @unlink(self::$dir.'/hits.log');
        @unlink(self::$dir.'/bodies.log');
    }

    private function needsServer(): void
    {
        if (self::$serverError !== null) {
            $this->markTestSkipped('local ingest server unavailable: '.self::$serverError);
        }
    }

    private function url(string $path): string
    {
        return 'http://127.0.0.1:'.self::$port.'/'.$path;
    }

    private function client(string $path): Client
    {
        return new Client($this->url($path), self::KEY, 0.5, 0.5);
    }

    private function setClient(Client $c): void
    {
        (fn () => $this->client = $c)->call($this->shipper);
    }

    private static function hits(string $path): int
    {
        return substr_count((string) @file_get_contents(self::$dir.'/hits.log'), "$path\n");
    }

    /** Every row of one type the capture server received. */
    private function rows(string $type): array
    {
        $out = [];
        foreach (array_filter(explode("\n", (string) @file_get_contents(self::$dir.'/bodies.log'))) as $line) {
            foreach (json_decode($line, true)[$type] ?? [] as $row) {
                $out[] = $row;
            }
        }

        return $out;
    }

    private function clear(): void
    {
        @unlink(self::$dir.'/bodies.log');
    }

    private function visit(string $path, array $server = []): \Illuminate\Testing\TestResponse
    {
        return $this->hit('GET', $path, $server);
    }

    public function test_inline_shipping_happens_only_after_the_response(): void
    {
        $this->needsServer();
        Route::get('/burst', function () {
            for ($i = 0; $i < 120; $i++) {
                Log::info("burst $i");
            }
            app()->instance('mid', self::hits('ok'));

            return 'ok';
        });
        $this->visit('/burst');

        $this->assertSame(0, $this->app['mid'], 'nothing shipped mid-request (120 logs > batch_size 50)');
        $this->assertCount(120, array_filter($this->rows('logs'), fn ($l) => str_starts_with($l['message'], 'burst ')), 'all of it shipped in terminate');
    }

    public function test_slow_ingest_costs_one_timeout_per_cool_down_window(): void
    {
        $this->needsServer();
        Route::get('/hello', fn () => 'hi'); // not /ping: that is in the default ignore_paths
        $this->setClient($this->client('slow'));

        $t = microtime(true);
        for ($i = 0; $i < 20; $i++) {
            $this->visit('/hello');
        }
        $elapsed = microtime(true) - $t;
        $this->assertLessThan(2.0, $elapsed, 'slow ingest: 20 requests cost ~one timeout, not 20');
        $this->assertSame(1, self::hits('slow'), 'slow ingest contacted once per cool-down window');

        $state = Client::breakerState();
        $this->assertTrue(Client::breakerOpen(), 'breaker open after first failure');
        $this->assertSame(1, $state['failures'], 'one failure counted');
        $this->assertEqualsWithDelta(30, $state['until'] - microtime(true), 3, 'breaker open for 30s');

        Cache::put(Client::BREAKER_KEY, ['until' => microtime(true) - 1, 'failures' => 1], 600); // cool-down elapsed
        $this->visit('/hello');
        $state = Client::breakerState();
        $this->assertSame(2, self::hits('slow'), 'after cool-down: one retry');
        $this->assertSame(2, $state['failures'], 'second failure counted');
        $this->assertEqualsWithDelta(60, $state['until'] - microtime(true), 3, 'exponential back-off (60s)');
        $this->assertSame($state, Cache::get(Client::BREAKER_KEY), 'breaker state lives in the app cache');
    }

    public function test_breaker_trips_on_5xx_and_429_and_closes_on_success(): void
    {
        $this->needsServer();
        $this->assertFalse($this->client('e500')->send(['logs' => [['m' => 1]]]), 'HTTP 500 is not delivered');
        $this->assertTrue(Client::breakerOpen(), 'HTTP 500 trips the breaker');

        $this->resetBreaker();
        $this->client('e429')->send(['logs' => [['m' => 1]]]);
        $this->assertEqualsWithDelta(120, Client::breakerState()['until'] - microtime(true), 3, '429 honours Retry-After (120s)');

        $before = self::hits('ok');
        (new ShipEnvelope(['logs' => [['m' => 1]]]))->handle();
        $this->assertSame($before, self::hits('ok'), 'ShipEnvelope drops while the breaker is open');

        $this->resetBreaker();
        Cache::put(Client::BREAKER_KEY, ['until' => microtime(true) - 1, 'failures' => 3], 600);
        Client::breakerState();
        $this->assertTrue($this->client('ok')->send(['logs' => [['m' => 1]]]), 'send after cool-down succeeds');
        $this->assertNull(Client::breakerState(), 'a successful send closes the breaker');
        $this->assertNull(Cache::get(Client::BREAKER_KEY), 'closed breaker removed from the cache');

        $before = self::hits('ok');
        $this->assertFalse($this->client('redirect')->send(['logs' => [['m' => 1]]]), 'a 3xx is not a delivery');
        $this->assertSame($before, self::hits('ok'), 'redirects are not followed (key never replayed)');
    }

    private function resetBreaker(): void
    {
        Cache::forget(Client::BREAKER_KEY);
        Client::breakerState();
    }

    public function test_worker_and_request_accumulators_are_bounded(): void
    {
        $this->needsServer();
        $this->monitor->traceId = str_repeat('a', 32);
        QueryJob::dispatch();
        $this->monitor->traceId = str_repeat('b', 32);
        QueryJob::dispatch();
        Artisan::call('queue:work', ['--stop-when-empty' => true, '--sleep' => 0]);
        $this->shipper->flush();

        $jobs = collect($this->rows('jobs'))->where('job_class', QueryJob::class)->values();
        $q = collect($this->rows('queries'));
        $this->assertLessThan(5, count($this->monitor->queries), 'worker accumulators emptied after each job (only the idle poll left)');
        $this->assertSame([], $this->monitor->httpCalls, 'worker http accumulator emptied');
        $this->assertCount(2, $jobs, 'both jobs shipped');
        $this->assertNotSame($jobs[0]['trace_id'], $jobs[1]['trace_id'], 'each job on its own trace');
        foreach ($jobs as $j) {
            $this->assertSame(RequestMonitor::MAX_ENTRIES, $q->where('trace_id', $j['trace_id'])->count(), 'job queries shipped on the job trace, capped at 500');
        }

        Route::get('/many', function () {
            for ($i = 0; $i < 600; $i++) {
                DB::select('select 1');
            }

            return 'ok';
        });
        $this->clear();
        $this->visit('/many');
        $req = collect($this->rows('requests'))->last();
        $this->assertSame(600, $req['db_queries'] ?? 0, 'request: total queries still counted');
        $this->assertSame(100, $req['queries_dropped'] ?? 0, 'request: dropped queries counted');
        $this->assertCount(500, $this->rows('queries'), 'request: kept queries capped');
    }

    public function test_scheduled_tasks_get_their_own_trace_and_only_the_output_tail(): void
    {
        $this->needsServer();
        $sched = $this->app->make(Schedule::class);
        $sched->call(function () {
            DB::select('select 7');
            DB::select('select 8');
        })->everyMinute()->name('db-task');
        $sched->exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg('echo str_repeat("a", 200000), "END";'))->everyMinute()->sendOutputTo(self::$dir.'/sched.out');
        $this->monitor->traceId = 'before-schedule';
        Artisan::call('schedule:run');
        $this->shipper->flush();

        $runs = collect($this->rows('scheduled'));
        $task = $runs->firstWhere('task', 'db-task');
        $this->assertNotNull($task, 'scheduled task shipped');
        $this->assertNotSame('before-schedule', $task['trace_id'], 'scheduled task gets its own trace');
        $this->assertSame(2, collect($this->rows('queries'))->where('trace_id', $task['trace_id'])->count(), 'its queries shipped on it');

        $exec = $runs->first(fn ($r) => str_contains($r['task'], 'str_repeat'));
        $this->assertSame(65536, config('observera.max_output'), 'max_output defaults to 65536');
        $this->assertNotNull($exec, 'exec task shipped');
        $this->assertStringEndsWith('END', $exec['output'], 'scheduled output is the tail');
        $this->assertLessThan(65700, strlen($exec['output']), 'scheduled output capped');
        $this->assertStringContainsString('last 65536 of 200003 bytes', $exec['output'], 'tail marker reports kept and original size');
    }

    public function test_invalid_utf8_never_drops_a_batch(): void
    {
        $this->needsServer();
        Log::info("bad \xC3\x28 utf8");
        Log::info('good line');
        $this->shipper->flush();
        $msgs = array_column($this->rows('logs'), 'message');

        $this->assertContains('good line', $msgs, 'batch with invalid UTF-8 still delivered');
        $this->assertCount(1, array_filter($msgs, fn ($m) => str_starts_with($m, 'bad ')), 'the invalid line itself delivered once');
    }

    public function test_the_apps_own_prevent_lazy_loading_wins(): void
    {
        foreach ([1, 2] as $u) {
            HUser::create(['id' => $u, 'name' => "u$u"]);
            HPost::create(['user_id' => $u, 'title' => 't']);
        }

        $this->expectException(LazyLoadingViolationException::class); // SDK handler not installed
        foreach (HUser::all() as $u) {
            $u->posts;
        }
    }

    public function test_body_capture_never_consumes_streams(): void
    {
        $this->needsServer();
        $tmp = fopen(self::$dir.'/sink.bin', 'w+');
        fwrite($tmp, '{"sink":true}');
        rewind($tmp);
        Http::fake([
            'nonseek.test/*' => fn () => new FulfilledPromise(new PsrResponse(200, ['Content-Type' => 'application/json'], new NoSeekStream(Utils::streamFor('{"streamed":true}')))),
            'file.test/*' => fn () => new FulfilledPromise(new PsrResponse(200, ['Content-Type' => 'application/json'], Utils::streamFor($tmp))),
            '*' => Http::response('{"ok":true}', 200),
        ]);
        $ns = Http::get('http://nonseek.test/x');
        $fs = Http::get('http://file.test/x');
        Http::attach('file', str_repeat('z', 5000), 'a.txt')->post('http://mp.test/up');
        $this->shipper->flush();
        $out = collect($this->rows('http_out'))->keyBy('host');

        $this->assertSame('', $out['nonseek.test']['resp_body'] ?? 'x', 'non-seekable response body not read');
        $this->assertSame('{"streamed":true}', $ns->body(), 'app still reads the non-seekable body');
        $this->assertSame('', $out['file.test']['resp_body'] ?? 'x', 'file/sink-backed body not read');
        $this->assertSame('{"sink":true}', $fs->body(), 'app still reads the file-backed body');
        $this->assertMatchesRegularExpression('/^\[multipart \d+ bytes\]$/', $out['mp.test']['req_body'] ?? '', 'multipart request recorded as a size only');
    }

    public function test_trace_header_hygiene(): void
    {
        $this->needsServer();
        Http::fake(['*' => Http::response('{"ok":true}', 200)]);
        Route::get('/trace', function () {
            Http::get('http://down.test/a');
            Http::get('http://other.test/b');
            Http::get('http://x.allowed.test/c');

            return 'ok';
        });
        $sentHeader = fn (string $host, string $name) => (string) ((Http::recorded(fn ($r) => parse_url($r->url(), PHP_URL_HOST) === $host)->last()[0] ?? null)?->header($name)[0] ?? '');
        $tid = '4bf92f3577b34da6a3ce929d0e0e4736';

        $this->visit('/trace', ['HTTP_TRACEPARENT' => "00-$tid-00f067aa0ba902b7-01", 'HTTP_TRACESTATE' => 'Bad State!!,x']);
        $this->assertStringContainsString($tid, $sentHeader('down.test', 'traceparent'), 'allowed host gets the traceparent');
        $this->assertSame('', $sentHeader('down.test', 'tracestate'), 'invalid tracestate dropped');
        $this->assertSame('', $sentHeader('other.test', 'traceparent'), 'propagate_trace_hosts allow-list: other host excluded');
        $this->assertNotSame('', $sentHeader('x.allowed.test', 'traceparent'), 'propagate_trace_hosts allow-list: wildcard match');

        $this->visit('/trace', ['HTTP_TRACEPARENT' => "00-$tid-00f067aa0ba902b7-01", 'HTTP_TRACESTATE' => 'v=1,t@sys=ok']);
        $this->assertSame('v=1,t@sys=ok', $sentHeader('down.test', 'tracestate'), 'valid tracestate passed through');
        $this->assertFalse(RequestMonitor::validTraceState(str_repeat('a', 513).'=1'), 'over-long tracestate rejected');
        $this->assertFalse(RequestMonitor::validTraceState(implode(',', array_map(fn ($i) => "k$i=v", range(1, 33)))), 'tracestate with > 32 members rejected');

        config(['observera.trust_incoming_trace' => false]);
        $this->clear();
        $this->visit('/trace', ['HTTP_TRACEPARENT' => "00-$tid-00f067aa0ba902b7-01"]);
        $this->assertNotSame($tid, collect($this->rows('requests'))->last()['trace_id'] ?? $tid, 'trust_incoming_trace=false ignores incoming traceparent');
        config(['observera.trust_incoming_trace' => true]);

        Route::get('/badheader', function () {
            app(RequestMonitor::class)->traceState = "bad\nvalue";

            return Http::get('http://down.test/z')->body();
        });
        $resp = $this->visit('/badheader');
        $this->assertSame(200, $resp->getStatusCode(), 'header middleware never throws into the app');
        $this->assertSame('{"ok":true}', $resp->getContent(), 'outbound call still completed');
    }

    public function test_cache_rows_are_capped_and_never_force_a_flush(): void
    {
        $this->needsServer();
        Route::get('/cache', function () {
            for ($i = 0; $i < 300; $i++) {
                Cache::get("k:$i");
            }
            app()->instance('mid', self::hits('ok'));

            return 'ok';
        });
        $before = self::hits('ok');
        $this->visit('/cache');

        $this->assertSame($before, $this->app['mid'], 'cache ops do not flush mid-request');
        $this->assertCount(LogShipper::MAX_CACHE, $this->rows('cache'), 'cache rows capped per request (200)');
    }
}

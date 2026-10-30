<?php
// Integration self-check for W3C distributed tracing against a real Laravel app:
// incoming traceparent continued, outbound Http calls carry it, queued jobs
// continue the dispatching request's trace, deny-list respected.
//
//   php tests/trace_check.php
//
// Same harness as queue_n1_check.php (borrows ../observera-api/vendor; override
// with OBSERVERA_LARAVEL_AUTOLOAD). Outbound calls are Http::fake'd.
putenv('APP_ENV=testing'); putenv('DB_CONNECTION=sqlite'); putenv('DB_DATABASE=:memory:');
putenv('QUEUE_CONNECTION=database'); putenv('OBSERVERA_KEY=obs_test_harness'); putenv('OBSERVERA_QUEUE=');
putenv('CACHE_STORE=array'); putenv('OBSERVERA_ENDPOINT=http://127.0.0.1:9'); // dead port: never leaves the machine
putenv('OBSERVERA_PROPAGATE_TRACE_DENY=denied.test,*.private.test');
putenv('APP_KEY=base64:'.base64_encode(str_repeat('k', 32)));
require getenv('OBSERVERA_LARAVEL_AUTOLOAD') ?: __DIR__.'/../../observera-api/vendor/autoload.php';
spl_autoload_register(function ($c) { if (str_starts_with($c, 'Observera\\Laravel\\')) { require __DIR__.'/../src/'.str_replace('\\', '/', substr($c, 18)).'.php'; } });

use Illuminate\Support\Facades\{Artisan, Http, Log, Route, Schema};
use Observera\Laravel\{LogShipper, ObserveraServiceProvider};
use Observera\Laravel\Transport\Client;

final class FakeClient extends Client { public array $sent = []; public function __construct() {} public function sendEnvelope(array $e): void { $this->sent[] = $e; } }
class TracedJob implements Illuminate\Contracts\Queue\ShouldQueue { use Illuminate\Bus\Queueable, Illuminate\Queue\InteractsWithQueue, Illuminate\Foundation\Bus\Dispatchable; public function handle() { Log::info('inside traced job'); } }

$base = sys_get_temp_dir().'/observera-sdk-trace-'.getmypid();
foreach (['bootstrap/cache', 'storage/framework/cache', 'storage/framework/views', 'storage/logs', 'routes'] as $d) { @mkdir("$base/$d", 0777, true); }
file_put_contents("$base/routes/web.php", '<?php');
register_shutdown_function(fn () => exec('rm -rf '.escapeshellarg($base)));
$app = Illuminate\Foundation\Application::configure(basePath: $base)->withRouting(web: "$base/routes/web.php")->withProviders([ObserveraServiceProvider::class])->withMiddleware(function ($m) {})->withExceptions(function ($e) {})->create();
$fake = new FakeClient;
$app->booting(fn () => $app->singleton(LogShipper::class, fn () => new LogShipper($fake, 'testing', 1000, 'debug', null)));
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

Schema::create('jobs', function ($t) { $t->id(); $t->string('queue')->index(); $t->longText('payload'); $t->unsignedTinyInteger('attempts'); $t->unsignedInteger('reserved_at')->nullable(); $t->unsignedInteger('available_at'); $t->unsignedInteger('created_at'); });
Schema::create('failed_jobs', function ($t) { $t->id(); $t->string('uuid')->unique(); $t->text('connection'); $t->text('queue'); $t->longText('payload'); $t->longText('exception'); $t->timestamp('failed_at')->useCurrent(); });

$checks = 0; $fail = 0;
$check = function (string $name, bool $ok, $detail = '') use (&$checks, &$fail) { $checks++; if (!$ok) $fail++; echo ($ok ? 'PASS ' : 'FAIL ')."$name".($detail !== '' ? "  [$detail]" : '')."\n"; };
$sent = function (string $type) use ($fake, $app) { $app->make(LogShipper::class)->flush(); $rows = []; foreach ($fake->sent as $e) foreach ($e[$type] ?? [] as $r) $rows[] = $r; return $rows; };
$sentHeader = fn (string $host, string $name) => (string) ((Http::recorded(fn ($r) => parse_url($r->url(), PHP_URL_HOST) === $host)->last()[0] ?? null)?->header($name)[0] ?? '');

Http::fake(['*' => Http::response('{"ok":true}', 200)]);
Route::get('/checkout', function () {
    Http::get('http://inventory.test/stock');
    Http::get('http://denied.test/x');
    Http::get('http://api.private.test/y');
    TracedJob::dispatch();

    return 'ok';
});
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$run = function (array $headers) use ($kernel) { $req = Illuminate\Http\Request::create('/checkout', 'GET', server: $headers); $resp = $kernel->handle($req); $kernel->terminate($req, $resp); return $resp; };

// 1. incoming traceparent is continued
$tid = '4bf92f3577b34da6a3ce929d0e0e4736'; $parent = '00f067aa0ba902b7';
$resp = $run(['HTTP_TRACEPARENT' => "00-$tid-$parent-01", 'HTTP_TRACESTATE' => 'vendor=abc']);
$check('route ran', $resp->getStatusCode() === 200, $resp->getStatusCode());
$req = collect($sent('requests'))->last();
$check('request continues incoming trace-id', ($req['trace_id'] ?? '') === $tid, $req['trace_id'] ?? 'missing');
$check('request records parent_span_id', ($req['parent_span_id'] ?? '') === $parent, $req['parent_span_id'] ?? 'missing');

// 2. outbound call carries traceparent with the same trace + a fresh span = http_out.span_id
$tp = $sentHeader('inventory.test', 'traceparent');
$ok = preg_match('/^00-([0-9a-f]{32})-([0-9a-f]{16})-01$/', $tp, $m);
$check('outbound traceparent has same trace-id', $ok && $m[1] === $tid, $tp);
$check('outbound span id is new (not the parent)', $ok && $m[2] !== $parent);
$check('tracestate passed through', $sentHeader('inventory.test', 'tracestate') === 'vendor=abc');
$out = collect($sent('http_out'))->firstWhere('host', 'inventory.test');
$check('http_out span_id equals sent span id', $ok && ($out['span_id'] ?? '') === $m[2], $out['span_id'] ?? 'missing');

// 3. deny-list (exact + wildcard)
$check('denied host gets no traceparent', $sentHeader('denied.test', 'traceparent') === '');
$check('wildcard-denied host gets no traceparent', $sentHeader('api.private.test', 'traceparent') === '');
$check('denied http_out has empty span_id', (collect($sent('http_out'))->firstWhere('host', 'denied.test')['span_id'] ?? 'x') === '');

// 4. job processed later continues the request trace in its own logs
$app->instance('request', Illuminate\Http\Request::create('/'));
$app->make(Observera\Laravel\Instrumentation\RequestMonitor::class)->traceId = 'worker-stale';
Artisan::call('queue:work', ['--stop-when-empty' => true, '--sleep' => 0]);
$log = collect($sent('logs'))->first(fn ($l) => ($l['message'] ?? '') === 'inside traced job');
$check('job log continues request trace', ($log['trace_id'] ?? '') === $tid, $log['trace_id'] ?? 'missing');
$job = collect($sent('jobs'))->firstWhere('job_class', 'TracedJob');
$check('job row on the same trace', ($job['trace_id'] ?? '') === $tid, $job['trace_id'] ?? 'missing');

// 5. no incoming header -> fresh W3C 32-hex trace id; invalid header ignored
$fake->sent = [];
$run(['HTTP_TRACEPARENT' => '00-'.str_repeat('0', 32).'-'.$parent.'-01']);
$req = collect($sent('requests'))->last();
$check('fresh trace id is 32 hex', (bool) preg_match('/^[0-9a-f]{32}$/', $req['trace_id'] ?? ''), $req['trace_id'] ?? '');
$check('all-zero traceparent ignored', ($req['parent_span_id'] ?? 'x') === '' && ($req['trace_id'] ?? '') !== str_repeat('0', 32));
$check('outbound uses the fresh trace', str_contains($sentHeader('inventory.test', 'traceparent'), $req['trace_id'] ?? '-'));

echo "\n$checks checks, $fail failed\n"; exit($fail ? 1 : 0);

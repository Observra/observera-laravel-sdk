<?php
// Integration self-check for the SDK's impact on the host app: circuit breaker
// against a slow / failing ingest, shipping only after the response, bounded
// memory in workers, envelope limits, invalid UTF-8, lazy-loading ownership,
// stream-safe body capture, scheduled output tail, trace header hygiene, cache
// rows never forcing a flush.
//
//   php tests/reliability_check.php
//
// Same harness as queue_n1_check.php, but shipping is REAL: a local capture
// server (php -S on a random high port, started and stopped here) plays the
// ingest endpoint — /ok records envelopes, /slow sleeps, /e500, /e429, /redirect.
putenv('APP_ENV=testing'); putenv('DB_CONNECTION=sqlite'); putenv('DB_DATABASE=:memory:');
putenv('QUEUE_CONNECTION=database'); putenv('OBSERVERA_KEY=obs_test_harness'); putenv('OBSERVERA_QUEUE=');
putenv('CACHE_STORE=array'); putenv('OBSERVERA_TIMEOUT=0.5'); putenv('OBSERVERA_CONNECT_TIMEOUT=0.5');
putenv('OBSERVERA_PROPAGATE_TRACE_HOSTS=down.test,*.allowed.test');
putenv('APP_KEY=base64:'.base64_encode(str_repeat('k', 32)));
$port = random_int(20000, 60000);
putenv("OBSERVERA_ENDPOINT=http://127.0.0.1:$port/ok");
require getenv('OBSERVERA_LARAVEL_AUTOLOAD') ?: __DIR__.'/../../observera-api/vendor/autoload.php';
spl_autoload_register(function ($c) { if (str_starts_with($c, 'Observera\\Laravel\\')) { require __DIR__.'/../src/'.str_replace('\\', '/', substr($c, 18)).'.php'; } });

use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\{NoSeekStream, Response as PsrResponse, Utils};
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\{Artisan, Cache, DB, Http, Log, Route, Schema};
use Observera\Laravel\{LogShipper, ObserveraServiceProvider};
use Observera\Laravel\Instrumentation\RequestMonitor;
use Observera\Laravel\Jobs\ShipEnvelope;
use Observera\Laravel\Transport\Client;

final class FakeClient extends Client { public array $sent = []; public function __construct() {} public function sendEnvelope(array $e): void { $this->sent[] = $e; } }
class HUser extends Model { protected $table = 'users'; public $timestamps = false; protected $guarded = []; public function posts() { return $this->hasMany(HPost::class, 'user_id'); } }
class HPost extends Model { protected $table = 'posts'; public $timestamps = false; protected $guarded = []; }
class QueryJob implements Illuminate\Contracts\Queue\ShouldQueue { use Illuminate\Bus\Queueable, Illuminate\Queue\InteractsWithQueue, Illuminate\Foundation\Bus\Dispatchable; public function handle() { for ($i = 0; $i < 600; $i++) DB::select('select ? as n', [$i]); } }
// the host app's own provider, registered AFTER the SDK: it wants lazy loading to throw
class AppLikeProvider extends Illuminate\Support\ServiceProvider { public function boot(): void { Model::preventLazyLoading(); } }

// ---- capture server ----
$base = sys_get_temp_dir().'/observera-sdk-reliability-'.getmypid();
foreach (['bootstrap/cache', 'storage/framework/cache', 'storage/framework/views', 'storage/logs', 'routes', 'srv'] as $d) { @mkdir("$base/$d", 0777, true); }
$base = realpath($base);
file_put_contents("$base/routes/web.php", '<?php');
file_put_contents("$base/srv/router.php", <<<'PHP'
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
$server = proc_open(['env', 'PHP_CLI_SERVER_WORKERS=4', PHP_BINARY, '-S', "127.0.0.1:$port", "$base/srv/router.php"], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
register_shutdown_function(function () use ($server, $base) { proc_terminate($server); exec('pkill -f '.escapeshellarg("$base/srv/router.php")); exec('rm -rf '.escapeshellarg($base)); });
for ($i = 0; $i < 50 && ! @fsockopen('127.0.0.1', $port); $i++) { usleep(100000); }

$app = Illuminate\Foundation\Application::configure(basePath: $base)->withRouting(web: "$base/routes/web.php")->withProviders([ObserveraServiceProvider::class, AppLikeProvider::class])->withMiddleware(function ($m) {})->withExceptions(function ($e) {})->create();
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

Schema::create('jobs', function ($t) { $t->id(); $t->string('queue')->index(); $t->longText('payload'); $t->unsignedTinyInteger('attempts'); $t->unsignedInteger('reserved_at')->nullable(); $t->unsignedInteger('available_at'); $t->unsignedInteger('created_at'); });
Schema::create('failed_jobs', function ($t) { $t->id(); $t->string('uuid')->unique(); $t->text('connection'); $t->text('queue'); $t->longText('payload'); $t->longText('exception'); $t->timestamp('failed_at')->useCurrent(); });
Schema::create('users', function ($t) { $t->id(); $t->string('name'); });
Schema::create('posts', function ($t) { $t->id(); $t->unsignedBigInteger('user_id'); $t->string('title'); });

$checks = 0; $fail = 0;
$check = function (string $name, bool $ok, $detail = '') use (&$checks, &$fail) { $checks++; if (!$ok) $fail++; echo ($ok ? 'PASS ' : 'FAIL ')."$name".($detail !== '' ? "  [$detail]" : '')."\n"; };
$shipper = $app->make(LogShipper::class);
$monitor = $app->make(RequestMonitor::class);
$key = 'obs_test_harness';
$setClient = fn (Client $c) => (fn () => $this->client = $c)->call($shipper);
$okClient = new Client("http://127.0.0.1:$port/ok", $key, 0.5, 0.5);
$resetBreaker = function () { Cache::forget(Client::BREAKER_KEY); Client::breakerState(); };
$hits = fn (string $path) => substr_count((string) @file_get_contents("$base/srv/hits.log"), "$path\n");
$envelopes = fn () => array_map(fn ($l) => json_decode($l, true), array_filter(explode("\n", (string) @file_get_contents("$base/srv/bodies.log"))));
$rows = function (string $type) use ($envelopes) { $out = []; foreach ($envelopes() as $e) foreach ($e[$type] ?? [] as $r) $out[] = $r; return $out; };
$clear = fn () => @unlink("$base/srv/bodies.log");
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$run = function (string $path, array $server = []) use ($kernel) { $req = Illuminate\Http\Request::create($path, 'GET', server: $server); $resp = $kernel->handle($req); $kernel->terminate($req, $resp); return $resp; };

// ---- 7. inline shipping happens after the response, behind a breaker ----
Route::get('/burst', function () use ($hits) { for ($i = 0; $i < 120; $i++) Log::info("burst $i"); app()->instance('mid', $hits('ok')); return 'ok'; });
$run('/burst');
$check('nothing shipped mid-request (120 logs > batch_size 50)', $app['mid'] === 0, $app['mid']);
$check('all of it shipped in terminate', count(array_filter($rows('logs'), fn ($l) => str_starts_with($l['message'], 'burst '))) === 120);

Route::get('/hello', fn () => 'hi'); // not /ping: that is in the default ignore_paths
$setClient(new Client("http://127.0.0.1:$port/slow", $key, 0.5, 0.5));
$t = microtime(true);
for ($i = 0; $i < 20; $i++) { $run('/hello'); }
$elapsed = microtime(true) - $t;
$check('slow ingest: 20 requests cost ~one timeout, not 20', $elapsed < 2.0, round($elapsed, 2).'s');
$check('slow ingest contacted once per cool-down window', $hits('slow') === 1, $hits('slow'));
$state = Client::breakerState();
$check('breaker open for 30s after first failure', Client::breakerOpen() && $state['failures'] === 1 && abs($state['until'] - microtime(true) - 30) < 3, json_encode($state));
Cache::put(Client::BREAKER_KEY, ['until' => microtime(true) - 1, 'failures' => 1], 600); // cool-down elapsed
$run('/hello');
$state = Client::breakerState();
$check('after cool-down: one retry, then exponential back-off (60s)', $hits('slow') === 2 && $state['failures'] === 2 && abs($state['until'] - microtime(true) - 60) < 3, json_encode($state));
$check('breaker state lives in the app cache', Cache::get(Client::BREAKER_KEY) === $state);

$resetBreaker();
$check('HTTP 500 trips the breaker', ! (new Client("http://127.0.0.1:$port/e500", $key, 0.5, 0.5))->send(['logs' => [['m' => 1]]]) && Client::breakerOpen());
$resetBreaker();
(new Client("http://127.0.0.1:$port/e429", $key, 0.5, 0.5))->send(['logs' => [['m' => 1]]]);
$check('429 honours Retry-After (120s)', abs(Client::breakerState()['until'] - microtime(true) - 120) < 3, json_encode(Client::breakerState()));
$before = $hits('ok');
(new ShipEnvelope(['logs' => [['m' => 1]]]))->handle();
$check('ShipEnvelope drops while the breaker is open', $hits('ok') === $before);
$resetBreaker(); Cache::put(Client::BREAKER_KEY, ['until' => microtime(true) - 1, 'failures' => 3], 600); Client::breakerState();
$check('a successful send closes the breaker', $okClient->send(['logs' => [['m' => 1]]]) && Client::breakerState() === null && Cache::get(Client::BREAKER_KEY) === null);
$before = $hits('ok');
$check('redirects are not followed (key never replayed)', ! (new Client("http://127.0.0.1:$port/redirect", $key, 0.5, 0.5))->send(['logs' => [['m' => 1]]]) && $hits('ok') === $before);
$resetBreaker(); $setClient($okClient); $clear();

// ---- 8. bounded accumulators in workers; job / scheduled queries shipped ----
$monitor->traceId = str_repeat('a', 32); QueryJob::dispatch(); $monitor->traceId = str_repeat('b', 32); QueryJob::dispatch();
Artisan::call('queue:work', ['--stop-when-empty' => true, '--sleep' => 0]);
$shipper->flush();
$jobs = collect($rows('jobs'))->where('job_class', 'QueryJob')->values();
$q = collect($rows('queries'));
$check('worker accumulators emptied after each job (only the idle poll left)', count($monitor->queries) < 5 && $monitor->httpCalls === [], count($monitor->queries));
$check('job queries shipped on the job trace, capped at 500', $jobs->count() === 2 && $jobs->every(fn ($j) => $q->where('trace_id', $j['trace_id'])->count() === RequestMonitor::MAX_ENTRIES) && $jobs[0]['trace_id'] !== $jobs[1]['trace_id'], $jobs->map(fn ($j) => $q->where('trace_id', $j['trace_id'])->count())->implode(','));
Route::get('/many', function () { for ($i = 0; $i < 600; $i++) DB::select('select 1'); return 'ok'; });
$clear(); $run('/many');
$req = collect($rows('requests'))->last();
$check('request: kept queries capped, total still counted', ($req['db_queries'] ?? 0) === 600 && ($req['queries_dropped'] ?? 0) === 100 && count($rows('queries')) === 500, json_encode(array_intersect_key($req ?? [], array_flip(['db_queries', 'queries_dropped']))));
$sched = $app->make(Illuminate\Console\Scheduling\Schedule::class);
$sched->call(function () { DB::select('select 7'); DB::select('select 8'); })->everyMinute()->name('db-task');
$outFile = "$base/sched.out";
$sched->exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg('echo str_repeat("a", 200000), "END";'))->everyMinute()->sendOutputTo($outFile);
$monitor->traceId = 'before-schedule'; $clear();
Artisan::call('schedule:run');
$shipper->flush();
$runs = collect($rows('scheduled'));
$task = $runs->firstWhere('task', 'db-task');
$check('scheduled task gets its own trace + its queries shipped on it', $task && $task['trace_id'] !== 'before-schedule' && collect($rows('queries'))->where('trace_id', $task['trace_id'])->count() === 2, json_encode($task['trace_id'] ?? null));

// ---- 14. scheduled output: only the tail ----
$exec = $runs->first(fn ($r) => str_contains($r['task'], 'str_repeat'));
$check('max_output defaults to 65536', config('observera.max_output') === 65536);
$check('scheduled output is the tail, capped', $exec && str_ends_with($exec['output'], 'END') && strlen($exec['output']) < 65700 && str_contains($exec['output'], 'last 65536 of 200003 bytes'), strlen($exec['output'] ?? ''));

// ---- 9. envelope limits ----
$parts = LogShipper::chunk(['logs' => array_fill(0, 2500, ['message' => 'x'])]);
$check('≤ 1,000 events per envelope', array_map(fn ($c) => count($c['logs']), $parts) === [1000, 1000, 500]);
$parts = LogShipper::chunk(['logs' => array_fill(0, 40, ['message' => str_repeat('m', 1_000_000)])]);
$check('≤ ~15MB per envelope', count($parts) === 3 && max(array_map(fn ($c) => strlen(Client::encode($c)), $parts)) <= LogShipper::MAX_ENVELOPE_BYTES, implode(',', array_map(fn ($c) => strlen(Client::encode($c)), $parts)));
$parts = LogShipper::chunk(['http_out' => [['resp_body' => str_repeat('r', 30_000_000), 'host' => 'h']]]);
$check('a single >20MB event is truncated, not sent whole', strlen($parts[0]['http_out'][0]['resp_body']) < 70000 && $parts[0]['http_out'][0]['host'] === 'h');
$fc = new FakeClient; $cap = new LogShipper($fc, 'testing', 50, 'debug'); $cap->deferred = true;
for ($i = 0; $i < 6000; $i++) { $cap->record('info', "e$i"); }
$check('deferred buffer never flushes mid-request', $fc->sent === []);
$cap->flush(force: true);
$logs = array_merge(...array_map(fn ($e) => $e['logs'], $fc->sent));
$check('hard cap 5,000: rest dropped and reported once', count($logs) === 5001 && str_contains(end($logs)['message'], 'dropped 1000 events') && max(array_map(fn ($e) => count($e['logs']), $fc->sent)) <= 1000, count($logs));

// ---- 10. invalid UTF-8 never drops a batch ----
$clear();
Log::info("bad \xC3\x28 utf8"); Log::info('good line');
$shipper->flush();
$msgs = array_column($rows('logs'), 'message');
$check('batch with invalid UTF-8 still delivered', in_array('good line', $msgs, true) && count(array_filter($msgs, fn ($m) => str_starts_with($m, 'bad '))) === 1, implode(' | ', $msgs));
$clean = LogShipper::utf8(['logs' => [['message' => "x\xC3\x28", 'ctx' => ['k' => "\xFF"]]]]);
$check('queued payload scrubbed to valid UTF-8', mb_check_encoding($clean['logs'][0]['message'], 'UTF-8') && mb_check_encoding($clean['logs'][0]['ctx']['k'], 'UTF-8'));

// ---- 11. the app's own preventLazyLoading wins ----
foreach ([1, 2] as $u) { HUser::create(['id' => $u, 'name' => "u$u"]); HPost::create(['user_id' => $u, 'title' => 't']); }
try { foreach (HUser::all() as $u) { $u->posts; } $threw = false; } catch (Illuminate\Database\LazyLoadingViolationException) { $threw = true; }
$check('app enabling preventLazyLoading keeps its exception (SDK handler not installed)', $threw);

// ---- 13. stream-safe body capture ----
$tmp = fopen("$base/sink.bin", 'w+'); fwrite($tmp, '{"sink":true}'); rewind($tmp);
Http::fake([
    'nonseek.test/*' => fn () => new FulfilledPromise(new PsrResponse(200, ['Content-Type' => 'application/json'], new NoSeekStream(Utils::streamFor('{"streamed":true}')))),
    'file.test/*' => fn () => new FulfilledPromise(new PsrResponse(200, ['Content-Type' => 'application/json'], Utils::streamFor($tmp))),
    '*' => Http::response('{"ok":true}', 200),
]);
$clear();
$ns = Http::get('http://nonseek.test/x');
$fs = Http::get('http://file.test/x');
Http::attach('file', str_repeat('z', 5000), 'a.txt')->post('http://mp.test/up');
$shipper->flush();
$out = collect($rows('http_out'))->keyBy('host');
$check('non-seekable response body not read', ($out['nonseek.test']['resp_body'] ?? 'x') === '' && $ns->body() === '{"streamed":true}', $ns->body());
$check('file/sink-backed body not read', ($out['file.test']['resp_body'] ?? 'x') === '' && $fs->body() === '{"sink":true}');
$check('multipart request recorded as a size only', (bool) preg_match('/^\[multipart \d+ bytes\]$/', $out['mp.test']['req_body'] ?? ''), $out['mp.test']['req_body'] ?? '');

// ---- 15. trace header hygiene ----
Route::get('/trace', function () { Http::get('http://down.test/a'); Http::get('http://other.test/b'); Http::get('http://x.allowed.test/c'); return 'ok'; });
$sentHeader = fn (string $host, string $name) => (string) ((Http::recorded(fn ($r) => parse_url($r->url(), PHP_URL_HOST) === $host)->last()[0] ?? null)?->header($name)[0] ?? '');
$tid = '4bf92f3577b34da6a3ce929d0e0e4736';
$run('/trace', ['HTTP_TRACEPARENT' => "00-$tid-00f067aa0ba902b7-01", 'HTTP_TRACESTATE' => 'Bad State!!,x']);
$check('invalid tracestate dropped', str_contains($sentHeader('down.test', 'traceparent'), $tid) && $sentHeader('down.test', 'tracestate') === '');
$check('propagate_trace_hosts allow-list (exact + wildcard)', $sentHeader('other.test', 'traceparent') === '' && $sentHeader('x.allowed.test', 'traceparent') !== '');
$run('/trace', ['HTTP_TRACEPARENT' => "00-$tid-00f067aa0ba902b7-01", 'HTTP_TRACESTATE' => 'v=1,t@sys=ok']);
$check('valid tracestate passed through', $sentHeader('down.test', 'tracestate') === 'v=1,t@sys=ok');
$check('over-long tracestate rejected', ! RequestMonitor::validTraceState(str_repeat('a', 513).'=1') && ! RequestMonitor::validTraceState(implode(',', array_map(fn ($i) => "k$i=v", range(1, 33)))));
config(['observera.trust_incoming_trace' => false]); $clear();
$run('/trace', ['HTTP_TRACEPARENT' => "00-$tid-00f067aa0ba902b7-01"]);
$check('trust_incoming_trace=false ignores incoming traceparent', (collect($rows('requests'))->last()['trace_id'] ?? $tid) !== $tid);
config(['observera.trust_incoming_trace' => true]);
Route::get('/badheader', function () { app(RequestMonitor::class)->traceState = "bad\nvalue"; return Http::get('http://down.test/z')->body(); });
$resp = $run('/badheader');
$check('header middleware never throws into the app', $resp->getStatusCode() === 200 && $resp->getContent() === '{"ok":true}', $resp->getStatusCode());

// ---- 16. cache rows: capped, never trigger a flush ----
Route::get('/cache', function () use ($hits) { for ($i = 0; $i < 300; $i++) Cache::get("k:$i"); app()->instance('mid', $hits('ok')); return 'ok'; });
$clear(); $before = $hits('ok');
$run('/cache');
$check('cache ops do not flush mid-request', $app['mid'] === $before);
$check('cache rows capped per request (200)', count($rows('cache')) === LogShipper::MAX_CACHE, count($rows('cache')));
$fc = new FakeClient; $con = new LogShipper($fc, 'testing', 5, 'debug');
for ($i = 0; $i < 50; $i++) { $con->recordCache(['op' => 'hit', 'key' => "k$i"]); }
$check('cache rows excluded from the batch_size trigger', $fc->sent === []);

echo "\n$checks checks, $fail failed\n"; exit($fail ? 1 : 0);

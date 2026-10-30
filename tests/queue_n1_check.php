<?php
// Integration self-check for the queue + N+1 instrumentation against a real Laravel
// app: in-memory sqlite, a real database queue run by queue:work, fake transport.
//
//   php tests/queue_n1_check.php
//
// Needs a Laravel install to borrow; defaults to the sibling observera-api's vendor.
// Override with OBSERVERA_LARAVEL_AUTOLOAD=/path/to/vendor/autoload.php
putenv('APP_ENV=testing'); putenv('DB_CONNECTION=sqlite'); putenv('DB_DATABASE=:memory:');
putenv('QUEUE_CONNECTION=database'); putenv('OBSERVERA_KEY=obs_test_harness'); putenv('OBSERVERA_QUEUE=');
putenv('CACHE_STORE=array'); putenv('OBSERVERA_ENDPOINT=http://127.0.0.1:9'); // dead port: never leaves the machine
 putenv('APP_KEY=base64:'.base64_encode(str_repeat('k', 32)));
require getenv('OBSERVERA_LARAVEL_AUTOLOAD') ?: __DIR__.'/../../observera-api/vendor/autoload.php';
spl_autoload_register(function ($c) { if (str_starts_with($c, 'Observera\\Laravel\\')) { require __DIR__.'/../src/'.str_replace('\\', '/', substr($c, 18)).'.php'; } });

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\{Artisan, DB, Route, Schema};
use Observera\Laravel\{LogShipper, ObserveraServiceProvider};
use Observera\Laravel\Transport\Client;

final class FakeClient extends Client { public array $sent = []; public function __construct() {} public function sendEnvelope(array $e): void { $this->sent[] = $e; } }
class HUser extends Model { protected $table = 'users'; public $timestamps = false; protected $guarded = []; public function posts() { return $this->hasMany(HPost::class, 'user_id'); } }
class HPost extends Model { protected $table = 'posts'; public $timestamps = false; protected $guarded = []; }
class OkJob implements Illuminate\Contracts\Queue\ShouldQueue { use Illuminate\Bus\Queueable, Illuminate\Queue\InteractsWithQueue, Illuminate\Foundation\Bus\Dispatchable; public function handle() { usleep(20000); } }
class FlakyJob implements Illuminate\Contracts\Queue\ShouldQueue { use Illuminate\Bus\Queueable, Illuminate\Queue\InteractsWithQueue, Illuminate\Foundation\Bus\Dispatchable; public $tries = 2; public function handle() { if ($this->attempts() === 1) throw new RuntimeException('flaky first attempt'); } }

// throwaway app skeleton in the temp dir
$base = sys_get_temp_dir().'/observera-sdk-check-'.getmypid();
foreach (['bootstrap/cache', 'storage/framework/cache', 'storage/framework/views', 'storage/logs', 'routes'] as $d) { @mkdir("$base/$d", 0777, true); }
file_put_contents("$base/routes/web.php", '<?php');
register_shutdown_function(fn () => exec('rm -rf '.escapeshellarg($base)));
$app = Illuminate\Foundation\Application::configure(basePath: $base)->withRouting(web: "$base/routes/web.php")->withProviders([ObserveraServiceProvider::class])->withMiddleware(function ($m) {})->withExceptions(function ($e) {})->create();
$fake = new FakeClient;
// after providers register, before they boot: the SDK's boot() captures this instance
$app->booting(fn () => $app->singleton(LogShipper::class, fn () => new LogShipper($fake, 'testing', 1000, 'debug', null)));
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

Schema::create('jobs', function ($t) { $t->id(); $t->string('queue')->index(); $t->longText('payload'); $t->unsignedTinyInteger('attempts'); $t->unsignedInteger('reserved_at')->nullable(); $t->unsignedInteger('available_at'); $t->unsignedInteger('created_at'); });
Schema::create('failed_jobs', function ($t) { $t->id(); $t->string('uuid')->unique(); $t->text('connection'); $t->text('queue'); $t->longText('payload'); $t->longText('exception'); $t->timestamp('failed_at')->useCurrent(); });
Schema::create('users', function ($t) { $t->id(); $t->string('name'); });
Schema::create('posts', function ($t) { $t->id(); $t->unsignedBigInteger('user_id'); $t->string('title'); });

$checks = 0; $fail = 0;
$check = function (string $name, bool $ok, $detail = '') use (&$checks, &$fail) { $checks++; if (!$ok) $fail++; echo ($ok ? 'PASS ' : 'FAIL ')."$name".($detail !== '' ? "  [$detail]" : '')."\n"; };
$sent = function (string $type) use ($fake, $app) { $app->make(LogShipper::class)->flush(); $rows = []; foreach ($fake->sent as $e) foreach ($e[$type] ?? [] as $r) $rows[] = $r; return $rows; };

// 1. dispatched from an HTTP route -> stamp on payload
$monitor = $app->make(Observera\Laravel\Instrumentation\RequestMonitor::class);
$monitor->traceId = 'trace-from-request';
Route::get('/orders/{id}', fn () => 'ok');
$req = Illuminate\Http\Request::create('/orders/5', 'GET'); $req->setRouteResolver(fn () => Route::getRoutes()->match($req)); $app->instance('request', $req);
OkJob::dispatch();
$payload = json_decode(DB::table('jobs')->value('payload'), true);
$check('payload carries observera stamp', isset($payload['observera']['queued_at']), json_encode($payload['observera'] ?? null));
$check('dispatch origin is the route', ($payload['observera']['from'] ?? '') === 'GET /orders/{id}', $payload['observera']['from'] ?? '');
$app->instance('request', Illuminate\Http\Request::create('/')); // console-like: a request with no route $monitor->traceId = 'worker-trace';
usleep(150000); // ~150ms in the queue
Artisan::call('queue:work', ['--stop-when-empty' => true, '--sleep' => 0]);
$jobs = $sent('jobs'); $ok = collect($jobs)->firstWhere('job_class', 'OkJob');
$check('job row has wait_ms >= 150', ($ok['wait_ms'] ?? 0) >= 150, $ok['wait_ms'] ?? 'missing');
$check('job row linked to dispatching trace', ($ok['trace_id'] ?? '') === 'trace-from-request', $ok['trace_id'] ?? '');
$check('job row has dispatched_from', ($ok['dispatched_from'] ?? '') === 'GET /orders/{id}');
$logs = $sent('logs'); $stop = collect($logs)->first(fn ($l) => str_contains($l['message'] ?? '', 'Queue worker stopped'));
$check('worker stop reported', $stop !== null, $stop['message'] ?? 'none');

// 2. released after exception, then succeeds
$fake->sent = [];
FlakyJob::dispatch();
Artisan::call('queue:work', ['--once' => true, '--sleep' => 0]);
Artisan::call('queue:work', ['--once' => true, '--sleep' => 0]);
$jobs = collect($sent('jobs'))->where('job_class', 'FlakyJob')->values();
$check('released row with exception', ($jobs[0]['status'] ?? '') === 'released' && str_contains($jobs[0]['exception'] ?? '', 'flaky first attempt'), ($jobs[0]['status'] ?? '').' / '.($jobs[0]['exception'] ?? ''));
$check('retry succeeds as ok, attempt 2, no wait_ms', ($jobs[1]['status'] ?? '') === 'ok' && ($jobs[1]['attempts'] ?? 0) === 2 && ($jobs[1]['wait_ms'] ?? -1) == 0, json_encode($jobs[1] ?? null));

// 3. N+1 lazy loading
$fake->sent = []; $monitor->traceId = 'req-n1';
foreach ([1, 2, 3] as $u) { HUser::create(['id' => $u, 'name' => "u$u"]); HPost::create(['user_id' => $u, 'title' => 't']); HPost::create(['user_id' => $u, 'title' => 't2']); }
$total = 0; foreach (HUser::all() as $u) { $total += $u->posts->count(); }
$check('relation still loads (app unchanged)', $total === 6, $total);
$n1 = collect($sent('logs'))->filter(fn ($l) => str_contains($l['message'] ?? '', 'N+1'));
$check('N+1 reported once per relation per request', $n1->count() === 1, $n1->pluck('message')->implode(' | '));
$single = HUser::find(1); $fake->sent = []; $single->posts;
$check('single-model lazy load not flagged', collect($sent('logs'))->filter(fn ($l) => str_contains($l['message'] ?? '', 'N+1'))->isEmpty());
$monitor->traceId = 'req-other'; foreach (HUser::all() as $u) { $u->posts; }
$check('new request reports again', collect($sent('logs'))->filter(fn ($l) => str_contains($l['message'] ?? '', 'N+1'))->count() === 1);

echo "\n$checks checks, $fail failed\n"; exit($fail ? 1 : 0);

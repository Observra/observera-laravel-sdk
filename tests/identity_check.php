<?php
// Integration self-check for user identity against a real Laravel app:
// a lookup made before login must not blank the user for the rest of the
// request, a reused (Octane-style) worker must not carry one person's identity
// into the next request, and outbound calls carry the user they were made for.
//
//   php tests/identity_check.php
//
// Same harness as queue_n1_check.php (borrows ../observera-api/vendor; override
// with OBSERVERA_LARAVEL_AUTOLOAD). Outbound calls are Http::fake'd.
putenv('APP_ENV=testing'); putenv('DB_CONNECTION=sqlite'); putenv('DB_DATABASE=:memory:');
putenv('QUEUE_CONNECTION=database'); putenv('OBSERVERA_KEY=obs_test_harness'); putenv('OBSERVERA_QUEUE=');
putenv('CACHE_STORE=array'); putenv('OBSERVERA_ENDPOINT=http://127.0.0.1:9'); // dead port: never leaves the machine
putenv('APP_KEY=base64:'.base64_encode(str_repeat('k', 32)));
require getenv('OBSERVERA_LARAVEL_AUTOLOAD') ?: __DIR__.'/../../observera-api/vendor/autoload.php';
spl_autoload_register(function ($c) { if (str_starts_with($c, 'Observera\\Laravel\\')) { require __DIR__.'/../src/'.str_replace('\\', '/', substr($c, 18)).'.php'; } });

use Illuminate\Support\Facades\{Auth, Http, Log, Route, Schema};
use Observera\Laravel\{LogShipper, ObserveraServiceProvider};
use Observera\Laravel\Transport\Client;

final class FakeClient extends Client { public array $sent = []; public function __construct() {} public function sendEnvelope(array $e): void { $this->sent[] = $e; } }
class HUser extends Illuminate\Foundation\Auth\User { protected $table = 'users'; public $timestamps = false; protected $guarded = []; }

$base = sys_get_temp_dir().'/observera-sdk-identity-'.getmypid();
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

Schema::create('users', function ($t) { $t->id(); $t->string('name'); $t->string('email'); $t->string('password')->default(''); });
$jane = HUser::create(['name' => 'Jane', 'email' => 'jane@shop.test']);

Http::fake(['*' => Http::response('{"ok":true}', 200)]);
Route::get('/pay', function () use ($jane) {
    Log::info('before auth');            // identity looked up while nobody is logged in yet
    Auth::guard()->setUser($jane);       // what the auth middleware does a moment later
    Http::post('http://payments.test/charge');
    Log::info('after auth');

    return 'ok';
});
Route::get('/public', function () {
    Http::get('http://cdn.test/asset');

    return 'ok';
});
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$run = function (string $path) use ($kernel, $app) {
    $req = Illuminate\Http\Request::create($path, 'GET');
    $resp = $kernel->handle($req);
    $kernel->terminate($req, $resp);
    $app['auth']->forgetGuards(); // Octane resets guards between requests; the SDK must too

    return $resp;
};

// 1. early lookup must not blank the request
$run('/pay');
$req = collect($sent('requests'))->last();
$check('request row has the user despite a log before auth', ($req['user_email'] ?? '') === 'jane@shop.test', $req['user_email'] ?? 'missing');
$out = collect($sent('http_out'))->firstWhere('host', 'payments.test');
$check('outbound call carries the user', ($out['user_email'] ?? '') === 'jane@shop.test' && ($out['user_id'] ?? '') === (string) $jane->id, json_encode(array_intersect_key($out ?? [], array_flip(['user_id', 'user_email', 'user_name']))));
$logs = collect($sent('logs'));
$check('log after auth is attributed', ($logs->firstWhere('message', 'after auth')['user_email'] ?? $logs->firstWhere('message', 'after auth')['user']['email'] ?? '') === 'jane@shop.test', json_encode($logs->firstWhere('message', 'after auth')));

// 2. same worker, next request by nobody: must not inherit Jane
$fake->sent = [];
$run('/public');
$req = collect($sent('requests'))->last();
$check('next request on the same worker is not attributed to the previous user', ($req['user_email'] ?? 'x') === '', $req['user_email'] ?? 'missing');
$out = collect($sent('http_out'))->firstWhere('host', 'cdn.test');
$check('its outbound call has no user either', ($out['user_email'] ?? 'x') === '', $out['user_email'] ?? 'missing');

echo "\n$checks checks, $fail failed\n"; exit($fail ? 1 : 0);

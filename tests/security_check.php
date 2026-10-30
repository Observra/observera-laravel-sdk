<?php
// Integration self-check for data minimisation / security against a real Laravel
// app: redaction (context, bodies, job payloads), QueryException + raw SQL
// literals, transport hardening, encrypted queue shipping, hashed session ids,
// send_pii, cache keys, source snippets, header masking.
//
//   php tests/security_check.php
//
// Same harness as queue_n1_check.php (borrows ../observera-api/vendor; override
// with OBSERVERA_LARAVEL_AUTOLOAD). Outbound calls are Http::fake'd.
putenv('APP_ENV=testing'); putenv('DB_CONNECTION=sqlite'); putenv('DB_DATABASE=:memory:');
putenv('QUEUE_CONNECTION=database'); putenv('OBSERVERA_KEY=obs_test_harness'); putenv('OBSERVERA_QUEUE=');
putenv('CACHE_STORE=array'); putenv('OBSERVERA_ENDPOINT=http://127.0.0.1:9'); // dead port: never leaves the machine
putenv('OBSERVERA_REDACT_KEYS=iban'); putenv('SESSION_DRIVER=array');
putenv('APP_KEY=base64:'.base64_encode(str_repeat('k', 32)));
require getenv('OBSERVERA_LARAVEL_AUTOLOAD') ?: __DIR__.'/../../observera-api/vendor/autoload.php';
spl_autoload_register(function ($c) { if (str_starts_with($c, 'Observera\\Laravel\\')) { require __DIR__.'/../src/'.str_replace('\\', '/', substr($c, 18)).'.php'; } });

use Illuminate\Support\Facades\{Artisan, Auth, Cache, DB, Http, Log, Route, Schema};
use Observera\Laravel\{LogShipper, ObserveraServiceProvider};
use Observera\Laravel\Instrumentation\Identity;
use Observera\Laravel\Jobs\ShipEnvelope;
use Observera\Laravel\Transport\Client;

final class FakeClient extends Client { public array $sent = []; public function __construct() {} public function sendEnvelope(array $e): void { $this->sent[] = $e; } }
class HUser extends Illuminate\Foundation\Auth\User { protected $table = 'users'; public $timestamps = false; protected $guarded = []; }
class SecretJob implements Illuminate\Contracts\Queue\ShouldQueue { use Illuminate\Bus\Queueable, Illuminate\Queue\InteractsWithQueue, Illuminate\Foundation\Bus\Dispatchable; public function __construct(public string $email = 'leak@shop.test', public string $password = 'hunter2') {} public function handle() {} }
class Tok implements Stringable { public function __toString(): string { return str_repeat('s', 20000); } }
class Js implements JsonSerializable { public function jsonSerialize(): mixed { return ['token' => 't0k', 'ok' => 1]; } }

$base = sys_get_temp_dir().'/observera-sdk-security-'.getmypid();
foreach (['bootstrap/cache', 'storage/framework/cache', 'storage/framework/views', 'storage/logs', 'routes', 'app', 'config'] as $d) { @mkdir("$base/$d", 0777, true); }
file_put_contents("$base/routes/web.php", '<?php');
file_put_contents("$base/app/Boom.php", "<?php\nfunction obs_boom() {\n    throw new RuntimeException('boom');\n}\n");
file_put_contents("$base/config/boomcfg.php", "<?php\nfunction obs_cfg_boom() {\n    obs_boom(); // SECRET_IN_CONFIG\n}\n");
$base = realpath($base); // macOS: /var → /private/var, as PHP reports file paths
register_shutdown_function(fn () => exec('rm -rf '.escapeshellarg($base)));
$app = Illuminate\Foundation\Application::configure(basePath: $base)->withRouting(web: "$base/routes/web.php")->withProviders([ObserveraServiceProvider::class])->withMiddleware(function ($m) {})->withExceptions(function ($e) {})->create();
$fake = new FakeClient;
$app->booting(fn () => $app->singleton(LogShipper::class, fn () => new LogShipper($fake, 'testing', 1000, 'debug', null)));
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

Schema::create('jobs', function ($t) { $t->id(); $t->string('queue')->index(); $t->longText('payload'); $t->unsignedTinyInteger('attempts'); $t->unsignedInteger('reserved_at')->nullable(); $t->unsignedInteger('available_at'); $t->unsignedInteger('created_at'); });
Schema::create('failed_jobs', function ($t) { $t->id(); $t->string('uuid')->unique(); $t->text('connection'); $t->text('queue'); $t->longText('payload'); $t->longText('exception'); $t->timestamp('failed_at')->useCurrent(); });
Schema::create('users', function ($t) { $t->id(); $t->string('name'); $t->string('email'); $t->string('password')->default(''); });
$jane = HUser::create(['name' => 'Jane', 'email' => 'jane@shop.test']);

$checks = 0; $fail = 0;
$check = function (string $name, bool $ok, $detail = '') use (&$checks, &$fail) { $checks++; if (!$ok) $fail++; echo ($ok ? 'PASS ' : 'FAIL ')."$name".($detail !== '' ? "  [$detail]" : '')."\n"; };
$sent = function (string $type) use ($fake, $app) { $app->make(LogShipper::class)->flush(); $rows = []; foreach ($fake->sent as $e) foreach ($e[$type] ?? [] as $r) $rows[] = $r; return $rows; };
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$run = function (string $path, array $server = []) use ($kernel, $app) { $req = Illuminate\Http\Request::create($path, 'GET', server: $server); $resp = $kernel->handle($req); $kernel->terminate($req, $resp); $app['auth']->forgetGuards(); return $resp; };

// ---- 1. recursive context redaction ----
$deep = ['l1' => ['l2' => ['l3' => ['l4' => ['l5' => ['l6' => 'too deep']]]]]];
Log::info('ctx', ['password' => 'p@ss', 'nested' => ['api_key' => 'k1', 'Authorization' => 'Bearer x', 'keep' => 'visible'], 'iban' => 'DE00',
    'user' => $jane, 'err' => new RuntimeException('bad'), 'str' => new Tok, 'js' => new Js, 'deep' => $deep, 'long' => str_repeat('x', 20000)]);
$ctx = collect($sent('logs'))->firstWhere('message', 'ctx')['context'] ?? [];
$check('top-level secret key filtered', ($ctx['password'] ?? '') === '[Filtered]');
$check('nested secret keys filtered', ($ctx['nested']['api_key'] ?? '') === '[Filtered]' && ($ctx['nested']['Authorization'] ?? '') === '[Filtered]' && ($ctx['nested']['keep'] ?? '') === 'visible', json_encode($ctx['nested'] ?? null));
$check('redact_keys extendable via OBSERVERA_REDACT_KEYS', ($ctx['iban'] ?? '') === '[Filtered]');
$check('model becomes a type label, not attributes', ($ctx['user'] ?? '') === 'HUser#'.$jane->id, json_encode($ctx['user'] ?? null));
$check('throwable → {class,message}', ($ctx['err']['class'] ?? '') === 'RuntimeException' && ($ctx['err']['message'] ?? '') === 'bad');
$check('Stringable → capped string', is_string($ctx['str'] ?? null) && strlen($ctx['str']) < 9000);
$check('JsonSerializable → its data, redacted', ($ctx['js']['token'] ?? '') === '[Filtered]' && ($ctx['js']['ok'] ?? 0) === 1, json_encode($ctx['js'] ?? null));
$check('depth capped', is_string($ctx['deep']['l1']['l2']['l3']['l4'] ?? null), json_encode($ctx['deep'] ?? null));
$check('long strings capped', strlen($ctx['long'] ?? '') < 9000 && str_contains($ctx['long'] ?? '', 'truncated'));

// ---- 2. outbound bodies: redacted, capped at 8192 by default, can be disabled ----
$check('max_body defaults to 8192', config('observera.max_body') === 8192);
Http::fake([
    'json.test/*' => Http::response(['access_token' => 'resp-secret', 'id' => 7], 200),
    'text.test/*' => Http::response('password=plain-secret&user=bob', 200, ['Content-Type' => 'text/plain']),
    'big.test/*' => Http::response(str_repeat('b', 100000), 200, ['Content-Type' => 'text/plain']),
    'form.test/*' => Http::response('ok', 200),
]);
$fake->sent = [];
Http::post('http://json.test/login', ['password' => 'req-secret', 'name' => 'n']);
Http::asForm()->post('http://form.test/x', ['client_secret' => 'form-secret', 'grant' => 'cc']);
Http::get('http://text.test/x');
$big = Http::get('http://big.test/x');
$out = collect($sent('http_out'))->keyBy('host');
$check('JSON request body key redacted', str_contains($out['json.test']['req_body'] ?? '', '[Filtered]') && ! str_contains($out['json.test']['req_body'] ?? '', 'req-secret'), $out['json.test']['req_body'] ?? '');
$check('JSON response body key redacted', ! str_contains($out['json.test']['resp_body'] ?? '', 'resp-secret') && str_contains($out['json.test']['resp_body'] ?? '', '"id":7'), $out['json.test']['resp_body'] ?? '');
$check('form body key redacted', ! str_contains($out['form.test']['req_body'] ?? '', 'form-secret') && str_contains($out['form.test']['req_body'] ?? '', 'grant=cc'), $out['form.test']['req_body'] ?? '');
$check('unparsable body: regex pass', ! str_contains($out['text.test']['resp_body'] ?? '', 'plain-secret') && str_contains($out['text.test']['resp_body'] ?? '', 'user=bob'), $out['text.test']['resp_body'] ?? '');
$check('big body capped to max_body', strlen($out['big.test']['resp_body'] ?? '') < 8300 && str_contains($out['big.test']['resp_body'] ?? '', 'of 100000 bytes'));
$check('app still reads the full body', strlen($big->body()) === 100000);
config(['observera.capture_bodies' => false]); $fake->sent = [];
Http::post('http://json.test/login', ['name' => 'n']);
$o = collect($sent('http_out'))->firstWhere('host', 'json.test');
$check('capture_bodies=false ships no bodies', ($o['req_body'] ?? 'x') === '' && ($o['resp_body'] ?? 'x') === '');
config(['observera.capture_bodies' => true]);

// ---- 3. job payloads: summary by default, raw only on opt-in (still redacted) ----
$fake->sent = [];
SecretJob::dispatch();
Artisan::call('queue:work', ['--stop-when-empty' => true, '--sleep' => 0]);
$job = collect($sent('jobs'))->firstWhere('job_class', 'SecretJob');
$p = json_decode($job['payload'] ?? '', true);
$check('job payload is a metadata summary', ($p['displayName'] ?? '') === 'SecretJob' && array_key_exists('maxTries', $p ?? []), $job['payload'] ?? '');
$check('job payload carries no constructor args', ! str_contains($job['payload'] ?? '', 'leak@shop.test') && ! str_contains($job['payload'] ?? '', 'hunter2'));
config(['observera.capture_job_payload' => true]); $fake->sent = [];
SecretJob::dispatch();
Artisan::call('queue:work', ['--stop-when-empty' => true, '--sleep' => 0]);
$job = collect($sent('jobs'))->firstWhere('job_class', 'SecretJob');
$check('capture_job_payload restores the raw body', str_contains($job['payload'] ?? '', 'leak@shop.test'), $job['payload'] ?? '');
$check('raw job payload still redacted', ! str_contains($job['payload'] ?? '', 'hunter2'));
config(['observera.capture_job_payload' => false]);

// ---- 4. QueryException + raw SQL literals ----
$fake->sent = [];
try { DB::select('select * from missing_table where email = ?', ['leak@sql.test']); } catch (Illuminate\Database\QueryException $e) { report($e); }
$ex = collect($sent('exceptions'))->firstWhere('class', Illuminate\Database\QueryException::class);
$check('QueryException message uses ? placeholders', $ex && ! str_contains($ex['message'], 'leak@sql.test') && str_contains($ex['message'], 'email = ?)'), $ex['message'] ?? 'missing');
$log = collect($sent('logs'))->first(fn ($l) => str_contains($l['message'], 'missing_table'));
$check('its log line is sanitised too', $log && ! str_contains($log['message'], 'leak@sql.test') && ! str_contains(json_encode($log['context']), 'leak@sql.test'), $log['message'] ?? 'missing');
Route::get('/raw', function () { DB::select("select 'literal-secret' as a, 42 as b where 1 = 1"); return 'ok'; });
$fake->sent = [];
$run('/raw');
$q = collect($sent('queries'))->first(fn ($q) => str_contains($q['sql'], 'as a'));
$check('string/number literals stripped from raw SQL', $q && ! str_contains($q['sql'], 'literal-secret') && ! str_contains($q['sql'], '42'), $q['sql'] ?? 'missing');

// ---- 5. transport hardening ----
$errLog = "$base/error.log"; ini_set('error_log', $errLog);
$c = new Client('http://ingest.example.com', 'k', 0.2, 0.2);
$t = microtime(true); $r1 = $c->send(['logs' => [['message' => 'x']]]); $r2 = $c->send(['logs' => [['message' => 'x']]]);
$check('plain-http public endpoint refused (no network)', ! $r1 && ! $r2 && microtime(true) - $t < 0.1);
$check('refusal logged once via error_log', substr_count((string) @file_get_contents($errLog), 'refusing to ship') === 1, trim((string) @file_get_contents($errLog)));
$check('https / localhost / *.test / *.local allowed', Client::endpointAllowed('https://x.com') && Client::endpointAllowed('http://localhost:8000') && Client::endpointAllowed('http://[::1]:80') && Client::endpointAllowed('http://api.observera.test') && Client::endpointAllowed('http://box.local') && ! Client::endpointAllowed('http://10.0.0.1'));
$guzzle = (fn () => $this->http)->call(new Client('https://x.test', 'k'));
$check('redirects off, TLS verify on', $guzzle->getConfig('allow_redirects') === false && $guzzle->getConfig('verify') === true);

// ---- 6. queue shipping: dedicated queue, encrypted, never parked in failed_jobs ----
$check('default queue is inline (null), queue_name observera', config('observera.queue') === null && config('observera.queue_name') === 'observera');
$job = new ShipEnvelope([]);
$check('ShipEnvelope encrypted + bounded retries', $job instanceof Illuminate\Contracts\Queue\ShouldBeEncrypted && $job->tries === 3 && $job->timeout === 10 && $job->failOnTimeout === false && $job->retryUntil() > now()->addMinutes(9));
DB::table('jobs')->delete();
$qs = new LogShipper(new Client('http://127.0.0.1:9', 'k', 0.3, 0.3), 'testing', 1000, 'debug', 'database', 'observera');
$qs->record('info', 'plaintext-marker'); $qs->flush();
$row = DB::table('jobs')->first();
$check('ship job on the observera queue, payload encrypted', $row && $row->queue === 'observera' && ! str_contains($row->payload, 'plaintext-marker'));
Artisan::call('queue:work', ['--queue' => 'observera', '--stop-when-empty' => true, '--sleep' => 0]);
$check('failed send discarded: no failed_jobs, queue empty', DB::table('failed_jobs')->count() === 0 && DB::table('jobs')->count() === 0);
$check('failed send opened the breaker', Client::breakerOpen());
$qs->record('info', 'during-cooldown'); $qs->flush();
$check('breaker open: batch dropped, not queued', DB::table('jobs')->count() === 0);
Cache::forget(Client::BREAKER_KEY); Client::breakerState();

// ---- 12. session id + anonymous id are keyed hashes ----
Route::get('/sess', function () { $s = app('session')->driver('array'); $s->start(); request()->setLaravelSession($s); app()->instance('sid', $s->getId()); return 'ok'; });
$fake->sent = [];
$run('/sess', ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_USER_AGENT' => 'UA/1']);
$req = collect($sent('requests'))->last(); $sid = $app['sid'];
$expect = 'web:'.substr(hash_hmac('sha256', $sid, config('app.key')), 0, 32);
$check('raw session id never shipped', ! str_contains(json_encode($fake->sent), $sid));
$check('session_key = HMAC(APP_KEY) of the id', ($req['session_key'] ?? '') === $expect, $req['session_key'] ?? '');
$sess = collect($sent('sessions'))->last();
$check('anonymous distinct_id = HMAC of ip|ua', ($sess['distinct_id'] ?? '') === 'anon:'.Identity::hash('203.0.113.9|UA/1', 16) && ($sess['distinct_id'] ?? '') !== 'anon:'.substr(sha1('203.0.113.9|UA/1'), 0, 16), $sess['distinct_id'] ?? '');

// ---- 17. send_pii=false: id only, no email/name/UA ----
Route::get('/me', function () use ($jane) { Auth::guard()->setUser($jane); Log::info('pii-line'); return 'ok'; });
config(['observera.send_pii' => false]); $fake->sent = [];
$run('/me', ['HTTP_USER_AGENT' => 'UA/2']);
$req = collect($sent('requests'))->last(); $log = collect($sent('logs'))->firstWhere('message', 'pii-line');
$check('send_pii=false keeps user_id, drops email/name', ($req['user_id'] ?? '') === (string) $jane->id && ($req['user_email'] ?? 'x') === '' && ($req['user_name'] ?? 'x') === '' && ($log['user_email'] ?? 'x') === '', json_encode([$req['user_email'] ?? null, $log['user_email'] ?? null]));
$check('send_pii=false drops the raw user agent', ($req['user_agent'] ?? 'x') === '');
config(['observera.send_pii' => true]);

// ---- 18 + 19. cache keys hashed, value_bytes never throws ----
$fake->sent = [];
Cache::put('orders:42:summary', 'abc', 60);
Cache::put('closure', fn () => 1, 60);
Cache::put('model', $jane, 60);
$hit = Cache::get('orders:42:summary');
$rows = collect($sent('cache'));
$check('cache key = pattern + short hash, never raw', $rows->isNotEmpty() && $rows->every(fn ($r) => ! str_contains($r['key'], 'orders:42')) && str_starts_with($rows->first()['key'], 'orders:{id}:summary#'), $rows->first()['key'] ?? '');
$check('value_bytes: closures / objects → 0, strings sized', $rows->firstWhere('key_pattern', 'closure')['value_bytes'] === 0 && $rows->firstWhere('key_pattern', 'model')['value_bytes'] === 0 && $rows->firstWhere('op', 'hit')['value_bytes'] === 3 && $hit === 'abc');

// ---- 20. source snippets ----
require "$base/app/Boom.php"; function_exists('obs_cfg_boom') || require "$base/config/boomcfg.php"; // Laravel already loaded config/*
$fake->sent = [];
try { obs_cfg_boom(); } catch (RuntimeException $e) { report($e); }
$ex = collect($sent('exceptions'))->firstWhere('message', 'boom');
$frames = collect($ex['stack'] ?? []);
$check('file paths relative to base_path', ($ex['file'] ?? '') === 'app/Boom.php' && ! str_contains(json_encode($ex['stack'] ?? []), $base), $ex['file'] ?? '');
$check('app frame has a snippet', isset($frames->firstWhere('file', 'app/Boom.php')['code']));
$check('config/ frame never has a snippet', $frames->firstWhere('file', 'config/boomcfg.php') !== null && ! isset($frames->firstWhere('file', 'config/boomcfg.php')['code']) && ! str_contains(json_encode($ex), 'SECRET_IN_CONFIG'));
config(['observera.capture_source' => false]); $fake->sent = [];
try { obs_boom(); } catch (RuntimeException $e) { report($e); }
$ex = collect($sent('exceptions'))->firstWhere('message', 'boom');
$check('capture_source=false: no snippets', collect($ex['stack'] ?? [])->every(fn ($f) => ! isset($f['code'])));

// ---- 21. header masking ----
Http::fake(['*' => Http::response('ok', 201, ['Location' => 'https://cb.test/return?token=abc&sig=1', 'X-Session-Id' => 's1', 'Content-Type' => 'text/plain'])]);
$fake->sent = [];
Http::withHeaders(['X-Signature' => 'sig-v', 'Ocp-Apim-Subscription-Key' => 'sub-v', 'X-Jwt-Assertion' => 'jwt-v', 'X-Credential' => 'cred-v', 'X-Trace-Note' => 'plain'])->get('http://hdr.test/x');
$o = collect($sent('http_out'))->firstWhere('host', 'hdr.test'); $all = json_encode([$o['req_headers'] ?? [], $o['resp_headers'] ?? []]);
$check('sensitive request/response headers masked', ! preg_match('/sig-v|sub-v|jwt-v|cred-v|"s1"/', $all) && ($o['req_headers']['X-Trace-Note'] ?? '') === 'plain', $all);
$check('Location query string stripped', ($o['resp_headers']['Location'] ?? '') === 'https://cb.test/return', $o['resp_headers']['Location'] ?? '');

echo "\n$checks checks, $fail failed\n"; exit($fail ? 1 : 0);

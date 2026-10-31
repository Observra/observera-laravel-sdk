<?php

namespace Observera\Laravel\Tests\Feature;

use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Observera\Laravel\Instrumentation\Identity;
use Observera\Laravel\Jobs\ShipEnvelope;
use Observera\Laravel\LogShipper;
use Observera\Laravel\Tests\Support\AuthUser;
use Observera\Laravel\Tests\Support\SecretJob;
use Observera\Laravel\Tests\TestCase;
use Observera\Laravel\Transport\Client;

/**
 * Data minimisation: redaction (context, bodies, job payloads), QueryException +
 * raw SQL literals, transport hardening, encrypted queue shipping, hashed
 * session ids, send_pii, cache keys, source snippets, header masking.
 * Outbound calls are Http::fake'd.
 */
class SecurityTest extends TestCase
{
    protected AuthUser $jane;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $defaults = (require dirname(__DIR__, 2).'/config/observera.php')['redact_keys'];
        $app['config']->set([
            'observera.redact_keys' => [...$defaults, 'iban'], // = OBSERVERA_REDACT_KEYS=iban
            'session.driver' => 'array',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->createQueueTables();
        Schema::create('users', function ($t) { $t->id(); $t->string('name'); $t->string('email'); $t->string('password')->default(''); });
        $this->jane = AuthUser::create(['name' => 'Jane', 'email' => 'jane@shop.test']);
    }

    private function visit(string $path, array $server = []): void
    {
        $this->hit('GET', $path, $server);
        $this->app['auth']->forgetGuards();
    }

    private function work(array $options = []): void
    {
        Artisan::call('queue:work', $options + ['--stop-when-empty' => true, '--sleep' => 0]);
    }

    public function test_log_context_is_recursively_redacted_and_bounded(): void
    {
        $deep = ['l1' => ['l2' => ['l3' => ['l4' => ['l5' => ['l6' => 'too deep']]]]]];
        Log::info('ctx', [
            'password' => 'p@ss',
            'nested' => ['api_key' => 'k1', 'Authorization' => 'Bearer x', 'keep' => 'visible'],
            'iban' => 'DE00',
            'user' => $this->jane,
            'err' => new \RuntimeException('bad'),
            'str' => new class implements \Stringable { public function __toString(): string { return str_repeat('s', 20000); } },
            'js' => new class implements \JsonSerializable { public function jsonSerialize(): mixed { return ['token' => 't0k', 'ok' => 1]; } },
            'deep' => $deep,
            'long' => str_repeat('x', 20000),
        ]);
        $ctx = collect($this->sent('logs'))->firstWhere('message', 'ctx')['context'] ?? [];

        $this->assertSame('[Filtered]', $ctx['password'] ?? '', 'top-level secret key filtered');
        $this->assertSame('[Filtered]', $ctx['nested']['api_key'] ?? '', 'nested api_key filtered');
        $this->assertSame('[Filtered]', $ctx['nested']['Authorization'] ?? '', 'nested Authorization filtered');
        $this->assertSame('visible', $ctx['nested']['keep'] ?? '', 'non-secret nested key kept');
        $this->assertSame('[Filtered]', $ctx['iban'] ?? '', 'redact_keys extendable via OBSERVERA_REDACT_KEYS');
        $this->assertSame(AuthUser::class.'#'.$this->jane->id, $ctx['user'] ?? '', 'model becomes a type label, not attributes');
        $this->assertSame(['class' => 'RuntimeException', 'message' => 'bad'], array_intersect_key($ctx['err'] ?? [], array_flip(['class', 'message'])), 'throwable → {class,message}');
        $this->assertIsString($ctx['str'] ?? null, 'Stringable → string');
        $this->assertLessThan(9000, strlen($ctx['str']), 'Stringable → capped');
        $this->assertSame('[Filtered]', $ctx['js']['token'] ?? '', 'JsonSerializable → its data, redacted');
        $this->assertSame(1, $ctx['js']['ok'] ?? 0, 'JsonSerializable keeps non-secret data');
        $this->assertIsString($ctx['deep']['l1']['l2']['l3']['l4'] ?? null, 'depth capped');
        $this->assertLessThan(9000, strlen($ctx['long'] ?? ''), 'long strings capped');
        $this->assertStringContainsString('truncated', $ctx['long'] ?? '', 'long strings carry a truncation marker');
    }

    public function test_outbound_bodies_are_redacted_and_capped(): void
    {
        $this->assertSame(8192, config('observera.max_body'), 'max_body defaults to 8192');
        Http::fake([
            'json.test/*' => Http::response(['access_token' => 'resp-secret', 'id' => 7], 200),
            'text.test/*' => Http::response('password=plain-secret&user=bob', 200, ['Content-Type' => 'text/plain']),
            'big.test/*' => Http::response(str_repeat('b', 100000), 200, ['Content-Type' => 'text/plain']),
            'form.test/*' => Http::response('ok', 200),
        ]);
        Http::post('http://json.test/login', ['password' => 'req-secret', 'name' => 'n']);
        Http::asForm()->post('http://form.test/x', ['client_secret' => 'form-secret', 'grant' => 'cc']);
        Http::get('http://text.test/x');
        $big = Http::get('http://big.test/x');
        $out = collect($this->sent('http_out'))->keyBy('host');

        $this->assertStringContainsString('[Filtered]', $out['json.test']['req_body'] ?? '', 'JSON request body key redacted');
        $this->assertStringNotContainsString('req-secret', $out['json.test']['req_body'] ?? '', 'JSON request secret gone');
        $this->assertStringNotContainsString('resp-secret', $out['json.test']['resp_body'] ?? '', 'JSON response body key redacted');
        $this->assertStringContainsString('"id":7', $out['json.test']['resp_body'] ?? '', 'JSON response keeps other keys');
        $this->assertStringNotContainsString('form-secret', $out['form.test']['req_body'] ?? '', 'form body key redacted');
        $this->assertStringContainsString('grant=cc', $out['form.test']['req_body'] ?? '', 'form body keeps other keys');
        $this->assertStringNotContainsString('plain-secret', $out['text.test']['resp_body'] ?? '', 'unparsable body: regex pass');
        $this->assertStringContainsString('user=bob', $out['text.test']['resp_body'] ?? '', 'unparsable body keeps other pairs');
        $this->assertLessThan(8300, strlen($out['big.test']['resp_body'] ?? ''), 'big body capped to max_body');
        $this->assertStringContainsString('of 100000 bytes', $out['big.test']['resp_body'] ?? '', 'cap marker reports the original size');
        $this->assertSame(100000, strlen($big->body()), 'app still reads the full body');
    }

    public function test_capture_bodies_false_ships_no_bodies(): void
    {
        config(['observera.capture_bodies' => false]);
        Http::fake(['json.test/*' => Http::response(['id' => 7], 200)]);
        Http::post('http://json.test/login', ['name' => 'n']);
        $o = collect($this->sent('http_out'))->firstWhere('host', 'json.test');

        $this->assertSame('', $o['req_body'] ?? 'x', 'capture_bodies=false ships no request body');
        $this->assertSame('', $o['resp_body'] ?? 'x', 'capture_bodies=false ships no response body');
    }

    public function test_job_payload_is_a_metadata_summary_by_default(): void
    {
        SecretJob::dispatch();
        $this->work();
        $job = collect($this->sent('jobs'))->firstWhere('job_class', SecretJob::class);
        $p = json_decode($job['payload'] ?? '', true) ?? [];

        $this->assertSame(SecretJob::class, $p['displayName'] ?? '', 'job payload is a metadata summary');
        $this->assertArrayHasKey('maxTries', $p, 'summary keeps retry metadata');
        $this->assertStringNotContainsString('leak@shop.test', $job['payload'] ?? '', 'job payload carries no constructor args (email)');
        $this->assertStringNotContainsString('hunter2', $job['payload'] ?? '', 'job payload carries no constructor args (password)');
    }

    public function test_capture_job_payload_restores_the_raw_body_still_redacted(): void
    {
        config(['observera.capture_job_payload' => true]);
        SecretJob::dispatch();
        $this->work();
        $job = collect($this->sent('jobs'))->firstWhere('job_class', SecretJob::class);

        $this->assertStringContainsString('leak@shop.test', $job['payload'] ?? '', 'capture_job_payload restores the raw body');
        $this->assertStringNotContainsString('hunter2', $job['payload'] ?? '', 'raw job payload still redacted');
    }

    public function test_query_exception_and_raw_sql_literals_are_sanitised(): void
    {
        try {
            DB::select('select * from missing_table where email = ?', ['leak@sql.test']);
        } catch (QueryException $e) {
            report($e);
        }
        $ex = collect($this->sent('exceptions'))->firstWhere('class', QueryException::class);
        $this->assertNotNull($ex, 'QueryException reported');
        $this->assertStringNotContainsString('leak@sql.test', $ex['message'], 'QueryException message has no bindings');
        $this->assertStringContainsString('email = ?)', $ex['message'], 'QueryException message uses ? placeholders');

        $log = collect($this->sent('logs'))->first(fn ($l) => str_contains($l['message'], 'missing_table'));
        $this->assertNotNull($log, 'QueryException log line shipped');
        $this->assertStringNotContainsString('leak@sql.test', $log['message'], 'its log line is sanitised too');
        $this->assertStringNotContainsString('leak@sql.test', json_encode($log['context']), 'its log context is sanitised too');

        Route::get('/raw', function () {
            DB::select("select 'literal-secret' as a, 42 as b where 1 = 1");

            return 'ok';
        });
        $this->fake->sent = [];
        $this->visit('/raw');
        $q = collect($this->sent('queries'))->first(fn ($q) => str_contains($q['sql'], 'as a'));
        $this->assertNotNull($q, 'raw query shipped');
        $this->assertStringNotContainsString('literal-secret', $q['sql'], 'string literals stripped from raw SQL');
        $this->assertStringNotContainsString('42', $q['sql'], 'number literals stripped from raw SQL');
    }

    public function test_transport_refuses_plain_http_and_is_hardened(): void
    {
        $warned = new \ReflectionProperty(Client::class, 'warnedInsecure');
        $warned->setValue(null, false); // once-per-process flag: reset so the test is order-independent
        $errLog = tempnam(sys_get_temp_dir(), 'obs-err');
        $previous = ini_set('error_log', $errLog);

        try {
            $c = new Client('http://ingest.example.com', 'k', 0.2, 0.2);
            $t = microtime(true);
            $r1 = $c->send(['logs' => [['message' => 'x']]]);
            $r2 = $c->send(['logs' => [['message' => 'x']]]);
            $elapsed = microtime(true) - $t;
            $log = (string) file_get_contents($errLog);
        } finally {
            ini_set('error_log', (string) $previous);
            @unlink($errLog);
        }

        $this->assertFalse($r1 || $r2, 'plain-http public endpoint refused');
        $this->assertLessThan(0.1, $elapsed, 'refusal touches no network');
        $this->assertSame(1, substr_count($log, 'refusing to ship'), 'refusal logged once via error_log');

        foreach (['https://x.com', 'http://localhost:8000', 'http://[::1]:80', 'http://api.observera.test', 'http://box.local'] as $ok) {
            $this->assertTrue(Client::endpointAllowed($ok), "$ok allowed");
        }
        $this->assertFalse(Client::endpointAllowed('http://10.0.0.1'), 'plain http to a non-local host refused');

        $guzzle = (fn () => $this->http)->call(new Client('https://x.test', 'k'));
        $this->assertFalse($guzzle->getConfig('allow_redirects'), 'redirects off');
        $this->assertTrue($guzzle->getConfig('verify'), 'TLS verify on');
    }

    public function test_queue_shipping_is_encrypted_bounded_and_never_parked_in_failed_jobs(): void
    {
        $this->assertNull(config('observera.queue'), 'default queue is inline (null)');
        $this->assertSame('observera', config('observera.queue_name'), 'default queue_name is observera');

        $job = new ShipEnvelope([]);
        $this->assertInstanceOf(ShouldBeEncrypted::class, $job, 'ShipEnvelope encrypted');
        $this->assertSame(3, $job->tries, 'bounded retries');
        $this->assertSame(10, $job->timeout, 'bounded timeout');
        $this->assertFalse($job->failOnTimeout, 'timeouts do not fail the job');
        $this->assertTrue($job->retryUntil() > now()->addMinutes(9), 'retryUntil ~10 minutes');

        $qs = new LogShipper(new Client('http://127.0.0.1:9', 'k', 0.3, 0.3), 'testing', 1000, 'debug', 'database', 'observera');
        $qs->record('info', 'plaintext-marker');
        $qs->flush();
        $row = DB::table('jobs')->first();
        $this->assertNotNull($row, 'ship job queued');
        $this->assertSame('observera', $row->queue, 'ship job on the observera queue');
        $this->assertStringNotContainsString('plaintext-marker', $row->payload, 'queued payload encrypted');

        $this->work(['--queue' => 'observera']);
        $this->assertSame(0, DB::table('failed_jobs')->count(), 'failed send discarded: no failed_jobs');
        $this->assertSame(0, DB::table('jobs')->count(), 'failed send discarded: queue empty');
        $this->assertTrue(Client::breakerOpen(), 'failed send opened the breaker');

        $qs->record('info', 'during-cooldown');
        $qs->flush();
        $this->assertSame(0, DB::table('jobs')->count(), 'breaker open: batch dropped, not queued');
    }

    public function test_session_id_and_anonymous_id_are_keyed_hashes(): void
    {
        Route::get('/sess', function () {
            $s = app('session')->driver('array');
            $s->start();
            request()->setLaravelSession($s);
            app()->instance('sid', $s->getId());

            return 'ok';
        });
        $this->visit('/sess', ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_USER_AGENT' => 'UA/1']);
        $req = collect($this->sent('requests'))->last();
        $sid = $this->app['sid'];

        $this->assertStringNotContainsString($sid, json_encode($this->fake->sent), 'raw session id never shipped');
        $this->assertSame('web:'.substr(hash_hmac('sha256', $sid, config('app.key')), 0, 32), $req['session_key'] ?? '', 'session_key = HMAC(APP_KEY) of the id');

        $sess = collect($this->sent('sessions'))->last();
        $this->assertSame('anon:'.Identity::hash('203.0.113.9|UA/1', 16), $sess['distinct_id'] ?? '', 'anonymous distinct_id = HMAC of ip|ua');
        $this->assertNotSame('anon:'.substr(sha1('203.0.113.9|UA/1'), 0, 16), $sess['distinct_id'] ?? '', 'anonymous distinct_id is not a plain sha1');
    }

    public function test_send_pii_false_ships_the_user_id_only(): void
    {
        $jane = $this->jane;
        Route::get('/me', function () use ($jane) {
            Auth::guard()->setUser($jane);
            Log::info('pii-line');

            return 'ok';
        });
        config(['observera.send_pii' => false]);
        $this->visit('/me', ['HTTP_USER_AGENT' => 'UA/2']);
        $req = collect($this->sent('requests'))->last();
        $log = collect($this->sent('logs'))->firstWhere('message', 'pii-line');

        $this->assertSame((string) $jane->id, $req['user_id'] ?? '', 'send_pii=false keeps user_id');
        $this->assertSame('', $req['user_email'] ?? 'x', 'send_pii=false drops request email');
        $this->assertSame('', $req['user_name'] ?? 'x', 'send_pii=false drops request name');
        $this->assertSame('', $log['user_email'] ?? 'x', 'send_pii=false drops log email');
        $this->assertSame('', $req['user_agent'] ?? 'x', 'send_pii=false drops the raw user agent');
    }

    public function test_cache_keys_are_hashed_and_value_bytes_never_throws(): void
    {
        Cache::put('orders:42:summary', 'abc', 60);
        Cache::put('closure', fn () => 1, 60);
        Cache::put('model', $this->jane, 60);
        $hit = Cache::get('orders:42:summary');
        $rows = collect($this->sent('cache'));

        $this->assertNotEmpty($rows, 'cache rows shipped');
        $this->assertTrue($rows->every(fn ($r) => ! str_contains($r['key'], 'orders:42')), 'raw cache key never shipped');
        $this->assertStringStartsWith('orders:{id}:summary#', $rows->first()['key'], 'cache key = pattern + short hash');
        $this->assertSame(0, $rows->firstWhere('key_pattern', 'closure')['value_bytes'], 'value_bytes: closure → 0');
        $this->assertSame(0, $rows->firstWhere('key_pattern', 'model')['value_bytes'], 'value_bytes: object → 0');
        $this->assertSame(3, $rows->firstWhere('op', 'hit')['value_bytes'], 'value_bytes: strings sized');
        $this->assertSame('abc', $hit, 'cache read unaffected');
    }

    public function test_source_snippets_are_relative_and_never_from_config(): void
    {
        // An app root outside vendor/ (testbench's skeleton lives in vendor/, so every frame there is "vendor").
        $base = sys_get_temp_dir().'/observera-sdk-security-src';
        @mkdir("$base/app", 0777, true);
        @mkdir("$base/config", 0777, true);
        file_put_contents("$base/app/Boom.php", "<?php\nfunction obs_boom() {\n    throw new RuntimeException('boom');\n}\n");
        file_put_contents("$base/config/boomcfg.php", "<?php\nfunction obs_cfg_boom() {\n    obs_boom(); // SECRET_IN_CONFIG\n}\n");
        $base = realpath($base); // macOS: /var → /private/var, as PHP reports file paths
        $this->beforeApplicationDestroyed(fn () => exec('rm -rf '.escapeshellarg($base)));
        function_exists('obs_boom') || require "$base/app/Boom.php";
        function_exists('obs_cfg_boom') || require "$base/config/boomcfg.php";
        $this->app->setBasePath($base);

        try {
            obs_cfg_boom();
        } catch (\RuntimeException $e) {
            report($e);
        }
        $ex = collect($this->sent('exceptions'))->firstWhere('message', 'boom');
        $frames = collect($ex['stack'] ?? []);
        $this->assertSame('app/Boom.php', $ex['file'] ?? '', 'file paths relative to base_path');
        $this->assertStringNotContainsString($base, json_encode($ex['stack'] ?? []), 'no absolute paths in the stack');
        $this->assertArrayHasKey('code', $frames->firstWhere('file', 'app/Boom.php') ?? [], 'app frame has a snippet');
        $this->assertNotNull($frames->firstWhere('file', 'config/boomcfg.php'), 'config/ frame present');
        $this->assertArrayNotHasKey('code', $frames->firstWhere('file', 'config/boomcfg.php') ?? [], 'config/ frame never has a snippet');
        $this->assertStringNotContainsString('SECRET_IN_CONFIG', json_encode($ex), 'config source never shipped');

        config(['observera.capture_source' => false]);
        $this->fake->sent = [];
        try {
            obs_boom();
        } catch (\RuntimeException $e) {
            report($e);
        }
        $ex = collect($this->sent('exceptions'))->firstWhere('message', 'boom');
        $this->assertNotNull($ex, 'exception reported');
        $this->assertTrue(collect($ex['stack'] ?? [])->every(fn ($f) => ! isset($f['code'])), 'capture_source=false: no snippets');
    }

    public function test_sensitive_headers_are_masked(): void
    {
        Http::fake(['*' => Http::response('ok', 201, ['Location' => 'https://cb.test/return?token=abc&sig=1', 'X-Session-Id' => 's1', 'Content-Type' => 'text/plain'])]);
        Http::withHeaders(['X-Signature' => 'sig-v', 'Ocp-Apim-Subscription-Key' => 'sub-v', 'X-Jwt-Assertion' => 'jwt-v', 'X-Credential' => 'cred-v', 'X-Trace-Note' => 'plain'])->get('http://hdr.test/x');
        $o = collect($this->sent('http_out'))->firstWhere('host', 'hdr.test');
        $all = json_encode([$o['req_headers'] ?? [], $o['resp_headers'] ?? []]);

        $this->assertDoesNotMatchRegularExpression('/sig-v|sub-v|jwt-v|cred-v|"s1"/', $all, 'sensitive request/response headers masked');
        $this->assertSame('plain', $o['req_headers']['X-Trace-Note'] ?? '', 'harmless header kept');
        $this->assertSame('https://cb.test/return', $o['resp_headers']['Location'] ?? '', 'Location query string stripped');
    }
}

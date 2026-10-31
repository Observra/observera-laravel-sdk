<?php

namespace Observera\Laravel\Tests\Feature;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Observera\Laravel\Tests\Support\AuthUser;
use Observera\Laravel\Tests\TestCase;

/** Identity attribution across auth timing and reused workers. */
class IdentityTest extends TestCase
{
    protected AuthUser $jane;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createQueueTables();
        Schema::create('users', function ($t) { $t->id(); $t->string('name'); $t->string('email'); $t->string('password')->default(''); });
        $this->jane = $jane = AuthUser::create(['name' => 'Jane', 'email' => 'jane@shop.test']);

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
    }

    private function visit(string $path): void
    {
        $this->hit('GET', $path);
        $this->app['auth']->forgetGuards(); // Octane resets guards between requests; the SDK must too
    }

    public function test_a_lookup_before_login_does_not_blank_the_request(): void
    {
        $this->visit('/pay');

        $req = collect($this->sent('requests'))->last();
        $this->assertSame('jane@shop.test', $req['user_email'] ?? '', 'request row has the user despite a log before auth');

        $out = collect($this->sent('http_out'))->firstWhere('host', 'payments.test');
        $this->assertSame('jane@shop.test', $out['user_email'] ?? '', 'outbound call carries the user email');
        $this->assertSame((string) $this->jane->id, $out['user_id'] ?? '', 'outbound call carries the user id');

        $log = collect($this->sent('logs'))->firstWhere('message', 'after auth');
        $this->assertSame('jane@shop.test', $log['user_email'] ?? $log['user']['email'] ?? '', 'log after auth is attributed');
    }

    public function test_a_reused_worker_does_not_carry_the_previous_user(): void
    {
        $this->visit('/pay');
        $this->sent('requests');
        $this->fake->sent = [];

        $this->visit('/public');

        $req = collect($this->sent('requests'))->last();
        $this->assertSame('', $req['user_email'] ?? 'x', 'next request on the same worker is not attributed to the previous user');
        $out = collect($this->sent('http_out'))->firstWhere('host', 'cdn.test');
        $this->assertSame('', $out['user_email'] ?? 'x', 'its outbound call has no user either');
    }
}

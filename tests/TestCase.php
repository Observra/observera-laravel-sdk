<?php

namespace Observera\Laravel\Tests;

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Observera\Laravel\LogShipper;
use Observera\Laravel\ObserveraServiceProvider;
use Observera\Laravel\Tests\Support\FakeClient;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Same harness as the legacy tests/*_check.php scripts: in-memory sqlite, a
 * database queue, a dead ingest endpoint and a fake transport bound before the
 * provider boots (its boot() captures the LogShipper instance).
 */
abstract class TestCase extends Orchestra
{
    protected FakeClient $fake;

    protected function setUp(): void
    {
        // Model's lazy-loading switches are static: a previous test's app left them on,
        // and the provider skips installing its handler when they are.
        Model::preventLazyLoading(false);
        Model::handleLazyLoadingViolationUsing(null);

        parent::setUp();
    }

    protected function getPackageProviders($app): array
    {
        return [ObserveraServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set([
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'database.default' => 'testing',
            'database.connections.testing' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false],
            'queue.default' => 'database',
            'cache.default' => 'array',
            'observera.key' => 'obs_test_harness',
            'observera.endpoint' => 'http://127.0.0.1:9', // dead port: never leaves the machine
            'observera.queue' => null,
        ]);

        // Runs after providers register, before they boot — same as the check scripts' $app->booting().
        $this->fake = new FakeClient;
        $fake = $this->fake;
        $app->booting(fn () => $app->singleton(LogShipper::class, fn () => new LogShipper($fake, 'testing', 1000, 'debug', null)));
    }

    /** Flush the shipper and return every row of one envelope type sent so far. */
    protected function sent(string $type): array
    {
        $this->app->make(LogShipper::class)->flush();
        $rows = [];
        foreach ($this->fake->sent as $envelope) {
            foreach ($envelope[$type] ?? [] as $row) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /** Run a request through the real HTTP kernel, terminate included (that is where the SDK flushes). */
    protected function hit(string $method, string $uri, array $server = []): TestResponse
    {
        $kernel = $this->app->make(HttpKernel::class);
        $request = Request::create($uri, $method, server: $server);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return TestResponse::fromBaseResponse($response);
    }

    protected function createQueueTables(): void
    {
        Schema::create('jobs', function ($t) { $t->id(); $t->string('queue')->index(); $t->longText('payload'); $t->unsignedTinyInteger('attempts'); $t->unsignedInteger('reserved_at')->nullable(); $t->unsignedInteger('available_at'); $t->unsignedInteger('created_at'); });
        Schema::create('failed_jobs', function ($t) { $t->id(); $t->string('uuid')->unique(); $t->text('connection'); $t->text('queue'); $t->longText('payload'); $t->longText('exception'); $t->timestamp('failed_at')->useCurrent(); });
    }
}

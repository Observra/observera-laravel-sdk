<?php

namespace Observera\Laravel\Tests\Feature;

use Illuminate\Support\Facades\Schema;
use Observera\Laravel\Instrumentation\RequestMonitor;
use Observera\Laravel\Tests\Support\HPost;
use Observera\Laravel\Tests\Support\HUser;
use Observera\Laravel\Tests\TestCase;

/** Port of the N+1 half of tests/queue_n1_check.php. */
class LazyLoadingTest extends TestCase
{
    protected RequestMonitor $monitor;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('users', function ($t) { $t->id(); $t->string('name'); });
        Schema::create('posts', function ($t) { $t->id(); $t->unsignedBigInteger('user_id'); $t->string('title'); });
        foreach ([1, 2, 3] as $u) {
            HUser::create(['id' => $u, 'name' => "u$u"]);
            HPost::create(['user_id' => $u, 'title' => 't']);
            HPost::create(['user_id' => $u, 'title' => 't2']);
        }
        $this->monitor = $this->app->make(RequestMonitor::class);
        $this->monitor->traceId = 'req-n1';
    }

    private function n1Logs(): \Illuminate\Support\Collection
    {
        return collect($this->sent('logs'))->filter(fn ($l) => str_contains($l['message'] ?? '', 'N+1'));
    }

    public function test_n_plus_one_is_reported_once_per_relation_and_the_relation_still_loads(): void
    {
        $total = 0;
        foreach (HUser::all() as $u) {
            $total += $u->posts->count();
        }

        $this->assertSame(6, $total, 'relation still loads (app unchanged)');
        $this->assertCount(1, $this->n1Logs(), 'N+1 reported once per relation per request');
    }

    public function test_single_model_lazy_load_is_not_flagged(): void
    {
        $single = HUser::find(1);
        $this->fake->sent = [];
        $single->posts;

        $this->assertTrue($this->n1Logs()->isEmpty(), 'single-model lazy load not flagged');
    }

    public function test_a_new_request_reports_again(): void
    {
        foreach (HUser::all() as $u) {
            $u->posts;
        }
        $this->assertCount(1, $this->n1Logs(), 'first request reports once');
        $this->fake->sent = [];
        $this->monitor->traceId = 'req-other';
        foreach (HUser::all() as $u) {
            $u->posts;
        }

        $this->assertCount(1, $this->n1Logs(), 'new request reports again');
    }
}

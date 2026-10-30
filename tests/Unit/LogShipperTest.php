<?php

namespace Observera\Laravel\Tests\Unit;

use Observera\Laravel\LogShipper;
use Observera\Laravel\Tests\Support\FakeClient;
use PHPUnit\Framework\TestCase;

/** Port of tests/shipper_check.php — framework-free. */
class LogShipperTest extends TestCase
{
    private function shipper(FakeClient $fake, int $batchSize = 10, string $minLevel = 'debug'): LogShipper
    {
        return new LogShipper($fake, 'production', batchSize: $batchSize, minLevel: $minLevel);
    }

    public function test_level_filter_and_batch_flush(): void
    {
        $fake = new FakeClient;
        $s = $this->shipper($fake, 2, 'warning');

        $s->record('info', 'ignored');
        $this->assertSame([], $fake->sent, 'info below warning must be dropped');

        $s->record('error', 'boom', ['trace_id' => 't1']);
        $this->assertSame([], $fake->sent, '1 record buffered, below batch size');

        $s->record('error', 'boom2');
        $this->assertCount(1, $fake->sent, 'batch flush at size 2');
        $this->assertCount(2, $fake->sent[0]['logs'], 'envelope carries both logs');
        $this->assertSame('boom', $fake->sent[0]['logs'][0]['message']);
        $this->assertSame('t1', $fake->sent[0]['logs'][0]['trace_id'], 'trace_id lifted from context');
        $this->assertSame('production', $fake->sent[0]['logs'][0]['channel'], 'channel = environment');
    }

    public function test_event_types_get_their_own_envelope_keys(): void
    {
        $fake = new FakeClient;
        $sh = $this->shipper($fake);
        $sh->recordRequest(['method' => 'GET', 'route' => '/x', 'status' => 200]);
        $sh->recordHttpOut(['service' => 'stripe', 'status' => 200]);
        $sh->recordException(['class' => 'RuntimeException', 'message' => 'x']);
        $sh->flush();

        $env = $fake->sent[0];
        $this->assertCount(1, $env['requests'], 'requests grouped');
        $this->assertSame('stripe', $env['http_out'][0]['service'], 'http_out grouped');
        $this->assertSame('RuntimeException', $env['exceptions'][0]['class'], 'exceptions grouped');
        $this->assertArrayNotHasKey('logs', $env, 'empty groups omitted');
    }

    public function test_throwable_in_context_is_scrubbed(): void
    {
        $fake = new FakeClient;
        $sh = $this->shipper($fake);
        $sh->record('error', 'e', ['exception' => new \RuntimeException('x')]);
        $sh->flush();

        $this->assertSame('RuntimeException', $fake->sent[0]['logs'][0]['context']['exception']['class'], 'throwable scrubbed');
    }

    public function test_failure_telemetry_ships_on_explicit_flush(): void
    {
        $fake = new FakeClient;
        $sh = $this->shipper($fake, 50);
        $sh->recordJob(['job_id' => 'j1', 'job_class' => 'App\Jobs\ChargeCard', 'status' => 'failed']);
        $sh->recordScheduled(['task' => 'reports:daily', 'status' => 'failed', 'exit_code' => 1]);
        $this->assertSame([], $fake->sent, 'nothing shipped before the explicit flush');

        $sh->flush();
        $this->assertCount(1, $fake->sent, 'explicit flush ships despite batch_size 50');
        $this->assertSame('failed', $fake->sent[0]['jobs'][0]['status'], 'jobs grouped');
        $this->assertSame('reports:daily', $fake->sent[0]['scheduled'][0]['task'], 'scheduled grouped');
    }

    public function test_request_events_keep_session_key_and_user_agent(): void
    {
        $fake = new FakeClient;
        $sh = $this->shipper($fake);
        $sh->recordRequest([
            'method' => 'POST', 'route' => '/pay', 'status' => 500,
            'session_key' => 'web:abc123', 'user_id' => '42', 'user_agent' => 'Mozilla/5.0 (iPhone)',
        ]);
        $sh->flush();

        $this->assertSame('web:abc123', $fake->sent[0]['requests'][0]['session_key'], 'session_key survives the envelope');
        $this->assertSame('Mozilla/5.0 (iPhone)', $fake->sent[0]['requests'][0]['user_agent'], 'user_agent survives the envelope');
    }

    public function test_identity_rides_every_log_line(): void
    {
        $fake = new FakeClient;
        $sh = $this->shipper($fake);
        $sh->record('error', 'Payment failed', ['trace_id' => 't9'], ['id' => '4812', 'email' => 'buyer@example.com', 'name' => 'Ada Byron']);
        $sh->flush();

        $log = $fake->sent[0]['logs'][0];
        $this->assertSame('4812', $log['user_id'], 'user id on the log line');
        $this->assertSame('buyer@example.com', $log['user_email'], 'user email on the log line');
        $this->assertSame('Ada Byron', $log['user_name'], 'user name on the log line');
    }

    public function test_anonymous_log_has_empty_identity_keys(): void
    {
        $fake = new FakeClient;
        $sh = $this->shipper($fake);
        $sh->record('info', 'cron tick');
        $sh->flush();

        $anon = $fake->sent[0]['logs'][0];
        $this->assertSame('', $anon['user_email'], 'anonymous log carries an empty email, not a missing key');
        $this->assertArrayHasKey('user_id', $anon, 'the key is always present');
    }

    public function test_cap_body_semantics(): void
    {
        $big = str_repeat('a', 1000);
        $this->assertSame($big, LogShipper::capBody($big, 0), 'max_body 0 captures in full, it does NOT cut to zero bytes');
        $this->assertSame($big, LogShipper::capBody($big, -1), 'a negative budget also disables the cap');
        $this->assertSame($big, LogShipper::capBody($big, 1000), 'a body exactly at the budget is untouched');
        $this->assertSame('', LogShipper::capBody('', 0), 'an empty body stays empty, no marker');

        $cut = LogShipper::capBody($big, 100);
        $this->assertStringStartsWith(str_repeat('a', 100), $cut, 'the kept prefix is the budget');
        $this->assertStringContainsString('stored 100 of 1000 bytes', $cut, 'the marker reports kept AND original');
    }

    public function test_cap_body_keeps_utf8_valid(): void
    {
        $jpCut = LogShipper::capBody(str_repeat('シ', 10), 8); // 8 bytes = 2 chars + 2 stray bytes
        $this->assertTrue(mb_check_encoding($jpCut, 'UTF-8'), 'a mid-character cut still yields valid UTF-8');
        $this->assertStringStartsWith('シシ', $jpCut, 'the cut lands on a character boundary');
        $this->assertTrue(mb_check_encoding(LogShipper::capBody("ok\xC3\x28", 0), 'UTF-8'), 'invalid source bytes are sanitised');
    }
}

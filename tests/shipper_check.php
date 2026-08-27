<?php

// Framework-free self-check: php tests/shipper_check.php
// Covers the non-trivial LogShipper logic — level filter, batch flush, scrub,
// multi-type envelope.

require __DIR__.'/../src/Transport/Client.php';
require __DIR__.'/../src/LogShipper.php';

use Observera\Laravel\LogShipper;
use Observera\Laravel\Transport\Client;

// fake transport: no Guzzle, just capture envelopes
final class FakeClient extends Client
{
    /** @var array<int, array> */
    public array $sent = [];

    public function __construct() {}

    public function sendEnvelope(array $envelope): void
    {
        $this->sent[] = $envelope;
    }
}

$fake = new FakeClient;
$s = new LogShipper($fake, 'production', batchSize: 2, minLevel: 'warning');

$s->record('info', 'ignored');                 // below min level → dropped
assert($fake->sent === [], 'info below warning must be dropped');

$s->record('error', 'boom', ['trace_id' => 't1']);
assert($fake->sent === [], '1 record buffered, below batch size');

$s->record('error', 'boom2');                  // hits batch size 2 → auto-flush
assert(count($fake->sent) === 1, 'batch flush at size 2');
assert(count($fake->sent[0]['logs']) === 2, 'envelope carries both logs');
assert($fake->sent[0]['logs'][0]['message'] === 'boom');
assert($fake->sent[0]['logs'][0]['trace_id'] === 't1', 'trace_id lifted from context');
assert($fake->sent[0]['logs'][0]['channel'] === 'production', 'channel = environment');

// requests + http_out buffer into their own envelope keys
$s2 = new FakeClient;
$sh = new LogShipper($s2, 'production', batchSize: 10, minLevel: 'debug');
$sh->recordRequest(['method' => 'GET', 'route' => '/x', 'status' => 200]);
$sh->recordHttpOut(['service' => 'stripe', 'status' => 200]);
$sh->recordException(['class' => 'RuntimeException', 'message' => 'x']);
$sh->flush();
$env = $s2->sent[0];
assert(count($env['requests']) === 1, 'requests grouped');
assert($env['http_out'][0]['service'] === 'stripe', 'http_out grouped');
assert($env['exceptions'][0]['class'] === 'RuntimeException', 'exceptions grouped');
assert(! isset($env['logs']), 'empty groups omitted');

// throwable in log context is scrubbed to class+message (JSON-safe)
$s3 = new FakeClient;
$sh3 = new LogShipper($s3, 'production', batchSize: 10, minLevel: 'debug');
$sh3->record('error', 'e', ['exception' => new RuntimeException('x')]);
$sh3->flush();
assert($s3->sent[0]['logs'][0]['context']['exception']['class'] === 'RuntimeException', 'throwable scrubbed');

// failure telemetry (jobs + scheduled) rides its own envelope keys, and an
// explicit flush ships it without waiting for batch_size — this is what the
// provider does on JobFailed/ScheduledTaskFailed so owner alerts are timely.
$s4 = new FakeClient;
$sh4 = new LogShipper($s4, 'production', batchSize: 50, minLevel: 'debug');
$sh4->recordJob(['job_id' => 'j1', 'job_class' => 'App\Jobs\ChargeCard', 'status' => 'failed']);
$sh4->recordScheduled(['task' => 'reports:daily', 'status' => 'failed', 'exit_code' => 1]);
assert($s4->sent === [], 'nothing shipped before the explicit flush');
$sh4->flush();
assert(count($s4->sent) === 1, 'explicit flush ships despite batch_size 50');
assert($s4->sent[0]['jobs'][0]['status'] === 'failed', 'jobs grouped');
assert($s4->sent[0]['scheduled'][0]['task'] === 'reports:daily', 'scheduled grouped');

// request events carry the session key + user agent the server groups sessions by
$s5 = new FakeClient;
$sh5 = new LogShipper($s5, 'production', batchSize: 10, minLevel: 'debug');
$sh5->recordRequest([
    'method' => 'POST', 'route' => '/pay', 'status' => 500,
    'session_key' => 'web:abc123', 'user_id' => '42', 'user_agent' => 'Mozilla/5.0 (iPhone)',
]);
$sh5->flush();
assert($s5->sent[0]['requests'][0]['session_key'] === 'web:abc123', 'session_key survives the envelope');
assert($s5->sent[0]['requests'][0]['user_agent'] === 'Mozilla/5.0 (iPhone)', 'user_agent survives the envelope');

// identity rides every log line — an id alone is not actionable in a dashboard
$s6 = new FakeClient;
$sh6 = new LogShipper($s6, 'production', batchSize: 10, minLevel: 'debug');
$sh6->record('error', 'Payment failed', ['trace_id' => 't9'], [
    'id' => '4812', 'email' => 'buyer@example.com', 'name' => 'Ada Byron',
]);
$sh6->flush();
$log = $s6->sent[0]['logs'][0];
assert($log['user_id'] === '4812', 'user id on the log line');
assert($log['user_email'] === 'buyer@example.com', 'user email on the log line');
assert($log['user_name'] === 'Ada Byron', 'user name on the log line');

// unauthenticated traffic: empty strings, never missing keys
$s7 = new FakeClient;
$sh7 = new LogShipper($s7, 'production', batchSize: 10, minLevel: 'debug');
$sh7->record('info', 'cron tick');
$sh7->flush();
$anon = $s7->sent[0]['logs'][0];
assert($anon['user_email'] === '', 'anonymous log carries an empty email, not a missing key');
assert(array_key_exists('user_id', $anon), 'the key is always present');

// --- capBody: the semantics that broke twice in production ---
//
// v1.1.1 shipped without the "0 disables the cap" guard, so max_body=0 meant
// mb_strcut($body, 0, 0) — every captured body became a bare truncation marker.
$big = str_repeat('a', 1000);

assert(LogShipper::capBody($big, 0) === $big, 'max_body 0 captures in full, it does NOT cut to zero bytes');
assert(LogShipper::capBody($big, -1) === $big, 'a negative budget also disables the cap');
assert(LogShipper::capBody($big, 1000) === $big, 'a body exactly at the budget is untouched');
assert(LogShipper::capBody('', 0) === '', 'an empty body stays empty, no marker');

$cut = LogShipper::capBody($big, 100);
assert(str_starts_with($cut, str_repeat('a', 100)), 'the kept prefix is the budget');
assert(str_contains($cut, 'stored 100 of 1000 bytes'), 'the marker reports kept AND original, not just original');

// UTF-8: a byte budget landing mid-character must not split it, or json_encode
// fails on the whole envelope and the batch is lost.
$jp = str_repeat('シ', 10);                    // 3 bytes each = 30 bytes
$jpCut = LogShipper::capBody($jp, 8);          // 8 bytes = 2 chars + 2 stray bytes
assert(mb_check_encoding($jpCut, 'UTF-8'), 'a mid-character cut still yields valid UTF-8');
assert(str_starts_with($jpCut, 'シシ'), 'the cut lands on a character boundary, not a byte one');

// Invalid input bytes are sanitised for the same reason.
assert(mb_check_encoding(LogShipper::capBody("ok\xC3\x28", 0), 'UTF-8'), 'invalid source bytes are sanitised');

echo "shipper_check OK\n";

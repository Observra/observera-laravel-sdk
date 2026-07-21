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

echo "shipper_check OK\n";

<?php

namespace Observera\Laravel\Tests\Support;

use Observera\Laravel\Transport\Client;

/** Captures envelopes instead of shipping them: no Guzzle, no network. */
final class FakeClient extends Client
{
    /** @var array<int, array<string, mixed>> */
    public array $sent = [];

    public function __construct() {}

    public function sendEnvelope(array $envelope): void
    {
        $this->sent[] = $envelope;
    }
}

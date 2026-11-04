<?php

namespace Observera\Laravel\Tests\Support;

use Observera\Laravel\Transport\Client;

/** Captures envelopes instead of shipping them: no Guzzle, no network. */
final class FakeClient extends Client
{
    /** @var array<int, array<string, mixed>> */
    public array $sent = [];

    public function __construct() {}

    /** @var array<int, array{path: string, payload: array<string, mixed>}> */
    public array $posted = [];

    /** What postTo() answers. */
    public array $reply = ['ok' => true, 'status' => 201, 'error' => null];

    public function sendEnvelope(array $envelope): void
    {
        $this->sent[] = $envelope;
    }

    public function postTo(string $path, array $payload): array
    {
        $this->posted[] = ['path' => $path, 'payload' => $payload];

        return $this->reply;
    }
}

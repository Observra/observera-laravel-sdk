<?php

namespace Observera\Laravel\Console;

use Illuminate\Console\Command;
use Observera\Laravel\Transport\Client;

/**
 * Fires one test event to Observera and reports the result — completes the
 * onboarding "connection test" (waiting for first event → received).
 */
class TestCommand extends Command
{
    protected $signature = 'observera:test {--message=Observera test event : message to send}';

    protected $description = 'Send a test event to Observera and report the result';

    public function handle(): int
    {
        $config = $this->laravel['config']['observera'];

        if (empty($config['key'])) {
            $this->error('OBSERVERA_KEY is not set. Add it to your .env, then run again.');

            return self::FAILURE;
        }

        $client = new Client(
            $config['endpoint'],
            (string) $config['key'],
            (float) $config['timeout'],
            (float) $config['connect_timeout'],
        );

        $this->line("Sending test event to <fg=gray>{$config['endpoint']}/intake/events</> …");

        $result = $client->post([[
            'level' => 'info',
            'message' => (string) $this->option('message'),
            'channel' => (string) $config['environment'],
            'context' => ['source' => 'observera:test'],
            'timestamp' => round(microtime(true) * 1000),
        ]]);

        if ($result['ok']) {
            $this->info('✓ Test event accepted. Check Observera → Logs (or the onboarding connection test, then Run again).');

            return self::SUCCESS;
        }

        $detail = $result['status'] ? "HTTP {$result['status']}" : 'connection failed';
        $this->error("✗ Could not send test event ({$detail}): {$result['error']}");
        $this->line('Check OBSERVERA_KEY (revoked?) and OBSERVERA_ENDPOINT reachability.');

        return self::FAILURE;
    }
}

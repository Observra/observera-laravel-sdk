<?php

namespace Observera\Laravel\Console;

use Illuminate\Console\Command;
use Observera\Laravel\Instrumentation\HostMetrics;
use Observera\Laravel\Transport\Client;

/**
 * Host metrics for servers that run no queue worker (web-only boxes). Queue
 * workers already report their host from their own loop, so most apps never
 * need this. Run it under Supervisor; sends directly (never through the
 * queue), and a failed send is simply skipped until the next interval.
 */
class AgentCommand extends Command
{
    protected $signature = 'observera:agent
        {--interval=15 : Seconds between samples}
        {--once : Send one sample and exit (for testing)}';

    protected $description = 'Report this server\'s CPU, memory, disk, load and network to Observera';

    private bool $stop = false;

    public function handle(): int
    {
        $config = $this->laravel['config']['observera'];

        if (empty($config['key'])) {
            $this->error('OBSERVERA_KEY is not set. Add it to your .env, then run again.');

            return self::FAILURE;
        }
        if (! HostMetrics::supported()) {
            $this->error('Host metrics need Linux with a readable /proc.');

            return self::FAILURE;
        }

        $client = new Client($config['endpoint'], (string) $config['key'], (float) $config['timeout'], (float) $config['connect_timeout']);
        $interval = max(5, (int) $this->option('interval'));
        $this->line('Reporting <fg=green>'.HostMetrics::identity()[1]."</> every {$interval}s to <fg=gray>{$config['endpoint']}</> …");

        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $this->stop = true);
            pcntl_signal(SIGINT, fn () => $this->stop = true);
        }

        do {
            $started = microtime(true);
            $ok = HostMetrics::send($client);
            if ($this->option('once')) {
                $ok ? $this->info('Sample sent.') : $this->error('Sample not delivered (check OBSERVERA_ENDPOINT / key).');

                return $ok ? self::SUCCESS : self::FAILURE;
            }
            $this->output->isVerbose() && $this->line(date('H:i:s').($ok ? ' sent' : ' skipped'));
            // sleep the rest of the interval in short steps so SIGTERM stops promptly
            $until = $started + $interval;
            while (! $this->stop && microtime(true) < $until) {
                usleep(200_000);
            }
        } while (! $this->stop);

        return self::SUCCESS;
    }
}

<?php

namespace Observera\Laravel\Console;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'observera:install';

    protected $description = 'Publish Observera config and print setup steps';

    public function handle(): int
    {
        $this->callSilent('vendor:publish', ['--tag' => 'observera-config']);

        $this->info('Observera config published to config/observera.php');
        $this->newLine();
        $this->line('Add to your .env:');
        $this->line('  <fg=gray>OBSERVERA_KEY=</><fg=yellow>obs_live_…</>   # from your Observera project → SDK keys');
        $this->line('  <fg=gray>OBSERVERA_ENDPOINT=</>https://ingest.observera.io');
        $this->newLine();
        $this->line('That\'s it — all Log::* calls now ship to Observera automatically.');
        $this->newLine();
        $this->line('Verify: <fg=cyan>php artisan observera:test</> (completes the onboarding connection test).');

        return self::SUCCESS;
    }
}

<?php

namespace Observera\Laravel\Console;

use Illuminate\Console\Command;
use Observera\Laravel\Support\Git;
use Observera\Laravel\Transport\Client;

/**
 * Marks a deploy in Observera — run it as the last step of a deploy:
 *
 *   php artisan observera:deploy
 *
 * Uses OBSERVERA_KEY (the environment's server key, which never expires), so
 * the deploy lands in that key's environment. Release, commit, branch and
 * author are detected from CI variables or the git checkout; each can be
 * overridden. A failed call warns but exits 0 — an observability outage must
 * not fail a deploy — unless --strict.
 */
class DeployCommand extends Command
{
    protected $signature = 'observera:deploy
        {release? : Release name; defaults to OBSERVERA_RELEASE / APP_VERSION / the short commit sha}
        {--sha= : Full commit sha (default: CI variables, then .git)}
        {--branch= : Branch (default: CI variables, then .git)}
        {--by= : Who deployed (default: CI actor, then the OS user)}
        {--notes= : Free-text notes shown on the deploy}
        {--duration= : How long the deploy took, in seconds}
        {--strict : Exit non-zero when Observera cannot be reached}';

    protected $description = 'Record a deployment in Observera';

    /** sha / branch / actor variables of the common CI systems, in lookup order. */
    protected const CI = [
        'sha' => ['GITHUB_SHA', 'CI_COMMIT_SHA', 'BITBUCKET_COMMIT', 'CIRCLE_SHA1', 'GIT_COMMIT', 'FORGE_DEPLOY_COMMIT', 'ENVOYER_COMMIT'],
        'branch' => ['GITHUB_REF_NAME', 'CI_COMMIT_REF_NAME', 'BITBUCKET_BRANCH', 'CIRCLE_BRANCH', 'GIT_BRANCH'],
        'by' => ['GITHUB_ACTOR', 'GITLAB_USER_LOGIN', 'BITBUCKET_STEP_TRIGGERER_UUID', 'CIRCLE_USERNAME', 'FORGE_DEPLOY_AUTHOR'],
    ];

    public function handle(): int
    {
        $config = $this->laravel['config']['observera'];

        if (empty($config['key'])) {
            return $this->problem('OBSERVERA_KEY is not set. Use the environment\'s server key (obs_live_…).');
        }

        $git = Git::head($this->laravel->basePath());
        $sha = $this->option('sha') ?: $this->ci('sha') ?: $git['sha'];
        $release = $this->argument('release') ?: ((string) ($config['release'] ?? '') ?: ($sha ? substr($sha, 0, 12) : null));

        if (! $release) {
            return $this->problem('Could not work out a release: pass one (php artisan observera:deploy v1.2.3) or set OBSERVERA_RELEASE.');
        }

        $payload = array_filter([
            'release' => (string) $release,
            'git_sha' => $sha ?: null,
            'branch' => $this->option('branch') ?: $this->ci('branch') ?: $git['branch'],
            'deployed_by' => $this->option('by') ?: $this->ci('by') ?: (get_current_user() ?: null),
            'notes' => $this->option('notes') ?: null,
            'duration_ms' => is_numeric($this->option('duration')) ? (int) round((float) $this->option('duration') * 1000) : null,
        ], fn ($v) => $v !== null && $v !== '');

        $client = $this->laravel->make(Client::class, [
            'endpoint' => (string) $config['endpoint'],
            'key' => (string) $config['key'],
            'timeout' => max(5.0, (float) $config['timeout']),
            'connectTimeout' => max(2.0, (float) $config['connect_timeout']),
        ]);

        $result = $client->postTo('intake/deployments', $payload);

        if ($result['ok']) {
            $this->info("✓ Deploy recorded: {$payload['release']}".(isset($payload['branch']) ? " ({$payload['branch']})" : ''));

            return self::SUCCESS;
        }

        $detail = $result['status'] ? "HTTP {$result['status']}" : 'connection failed';

        return $this->problem("Could not record the deploy ({$detail}): {$result['error']}");
    }

    protected function ci(string $field): ?string
    {
        foreach (self::CI[$field] as $var) {
            $value = getenv($var);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /** Warn and carry on, or fail under --strict. */
    protected function problem(string $message): int
    {
        if ($this->option('strict')) {
            $this->error('✗ '.$message);

            return self::FAILURE;
        }
        $this->warn('! '.$message.' (deploy continues; pass --strict to fail instead)');

        return self::SUCCESS;
    }
}

<?php

namespace Observera\Laravel\Tests\Feature;

use Observera\Laravel\Support\Git;
use Observera\Laravel\Tests\Support\FakeClient;
use Observera\Laravel\Tests\TestCase;
use Observera\Laravel\Transport\Client;

class DeployCommandTest extends TestCase
{
    protected FakeClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = new FakeClient;
        $this->app->bind(Client::class, fn () => $this->client);
    }

    protected function tearDown(): void
    {
        foreach (['GITHUB_SHA', 'GITHUB_REF_NAME', 'GITHUB_ACTOR'] as $var) {
            putenv($var);
        }
        parent::tearDown();
    }

    public function test_it_records_the_deploy_from_ci_variables(): void
    {
        putenv('GITHUB_SHA='.str_repeat('c', 40));
        putenv('GITHUB_REF_NAME=main');
        putenv('GITHUB_ACTOR=dilip');
        config(['observera.release' => '']);

        $this->artisan('observera:deploy', ['--notes' => 'hotfix', '--duration' => '84.5'])
            ->expectsOutputToContain('Deploy recorded: cccccccccccc (main)')
            ->assertSuccessful();

        $this->assertSame('intake/deployments', $this->client->posted[0]['path']);
        $this->assertSame([
            'release' => 'cccccccccccc',
            'git_sha' => str_repeat('c', 40),
            'branch' => 'main',
            'deployed_by' => 'dilip',
            'notes' => 'hotfix',
            'duration_ms' => 84500,
        ], $this->client->posted[0]['payload']);
    }

    public function test_explicit_release_and_options_win(): void
    {
        putenv('GITHUB_SHA='.str_repeat('c', 40));
        config(['observera.release' => 'from-config']);

        $this->artisan('observera:deploy', ['release' => 'v2.14.3', '--sha' => str_repeat('d', 40), '--branch' => 'release/2.14', '--by' => 'ana'])
            ->assertSuccessful();

        $p = $this->client->posted[0]['payload'];
        $this->assertSame(['v2.14.3', str_repeat('d', 40), 'release/2.14', 'ana'], [$p['release'], $p['git_sha'], $p['branch'], $p['deployed_by']]);

        // no release argument: the configured release is used
        $this->artisan('observera:deploy')->assertSuccessful();
        $this->assertSame('from-config', $this->client->posted[1]['payload']['release']);
    }

    public function test_a_failed_call_never_fails_the_deploy_unless_strict(): void
    {
        config(['observera.release' => 'v1']);
        $this->client->reply = ['ok' => false, 'status' => 401, 'error' => 'Invalid or revoked API key.'];

        $this->artisan('observera:deploy')
            ->expectsOutputToContain('Could not record the deploy (HTTP 401)')
            ->assertSuccessful();
        $this->artisan('observera:deploy', ['--strict' => true])->assertFailed();

        config(['observera.key' => '']);
        $this->artisan('observera:deploy', ['--strict' => true])
            ->expectsOutputToContain('OBSERVERA_KEY is not set')
            ->assertFailed();
    }

    public function test_git_head_is_read_without_a_git_binary(): void
    {
        $dir = sys_get_temp_dir().'/obs-git-'.uniqid();
        mkdir("$dir/.git/refs/heads", 0777, true);
        $sha = str_repeat('e', 40);

        file_put_contents("$dir/.git/HEAD", "ref: refs/heads/main\n");
        file_put_contents("$dir/.git/refs/heads/main", "$sha\n");
        $this->assertSame(['sha' => $sha, 'branch' => 'main'], Git::head($dir));

        // gc'd: ref only in packed-refs
        unlink("$dir/.git/refs/heads/main");
        file_put_contents("$dir/.git/packed-refs", "# pack-refs with: peeled\n$sha refs/heads/main\n");
        $this->assertSame(['sha' => $sha, 'branch' => 'main'], Git::head($dir));

        // detached (CI checkouts)
        file_put_contents("$dir/.git/HEAD", "$sha\n");
        $this->assertSame(['sha' => $sha, 'branch' => null], Git::head($dir));

        $this->assertSame(['sha' => null, 'branch' => null], Git::head($dir.'/nope'));
    }
}

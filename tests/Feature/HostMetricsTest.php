<?php

namespace Observera\Laravel\Tests\Feature;

use Observera\Laravel\Instrumentation\HostMetrics;
use Observera\Laravel\Tests\TestCase;

/** /proc → OTLP host metrics, read from a fake /proc tree. */
class HostMetricsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/obs-proc-'.bin2hex(random_bytes(4));
        $this->file('proc/stat', "cpu  700 0 300 9000 0 0 0 0 0 0\ncpu0 400 0 100 4000 500 0 0 0 0 0\ncpu1 300 0 200 4500 0 0 0 0 0 0\nintr 1\n");
        $this->file('proc/meminfo', "MemTotal:        8000000 kB\nMemFree:  100 kB\nMemAvailable:    2000000 kB\n");
        $this->file('proc/loadavg', "1.25 0.90 0.50 2/300 12345\n");
        $this->file('proc/net/dev', "Inter-|   Receive\n face |bytes\n    lo: 999 0 0 0 0 0 0 0 999 0 0 0 0 0 0 0\n  eth0: 1000 5 0 0 0 0 0 0 500 4 0 0 0 0 0 0\ndocker0: 777 0 0 0 0 0 0 0 777 0 0 0 0 0 0 0\nveth12: 66 0 0 0 0 0 0 0 66 0 0 0 0 0 0 0\n");
        $this->file('proc/1/cgroup', "0::/init.scope\n");
        $this->file('proc/sys/kernel/random/boot_id', "6f1c2b9e-1111-2222-3333-444455556666\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));
        parent::tearDown();
    }

    private function file(string $path, string $body): void
    {
        @mkdir(dirname($this->root.'/'.$path), 0777, true);
        file_put_contents($this->root.'/'.$path, $body);
    }

    /** @return array<string, array<int, array<string, mixed>>> metric name => data points */
    private function metrics(array $body): array
    {
        $out = [];
        foreach ($body['resourceMetrics'][0]['scopeMetrics'][0]['metrics'] as $m) {
            $out[$m['name']] = ($m['sum'] ?? $m['gauge'])['dataPoints'];
        }

        return $out;
    }

    private function attrs(array $body): array
    {
        return array_column(array_map(fn ($a) => [$a['key'], $a['value']['stringValue']], $body['resourceMetrics'][0]['resource']['attributes']), 1, 0);
    }

    public function test_bare_metal_host_reports_cpu_memory_disk_load_and_network(): void
    {
        $body = HostMetrics::collect($this->root);
        $m = $this->metrics($body);

        // cpu0: idle = 4000 + iowait 500, busy = 400 + 100 → seconds at USER_HZ 100
        $this->assertSame(45.0, $m['system.cpu.time'][0]['asDouble']);
        $this->assertSame(5.0, $m['system.cpu.time'][1]['asDouble']);
        $this->assertCount(4, $m['system.cpu.time'], 'two cores × idle/busy; the aggregate cpu line is skipped');
        $this->assertSame(6_000_000.0 * 1024, $m['system.memory.usage'][0]['asDouble'], 'used = total - available');
        $this->assertArrayHasKey('system.filesystem.usage', $m);
        $this->assertSame(1.25, $m['system.cpu.load_average.1m'][0]['asDouble']);
        $this->assertSame([1000.0, 500.0], array_column($m['system.network.io'], 'asDouble'), 'only eth0: lo, docker0, veth skipped');

        $a = $this->attrs($body);
        $this->assertSame(gethostname(), $a['host.id']);
        $this->assertSame('linux', $a['os.type']);
    }

    public function test_container_uses_boot_id_and_leaves_network_out(): void
    {
        $this->file('.dockerenv', '');

        $body = HostMetrics::collect($this->root);
        $a = $this->attrs($body);

        $this->assertSame('boot:6f1c2b9e-1111-2222-3333-444455556666', $a['host.id'], 'same for every container on the machine');
        $this->assertStringStartsWith('host-', $a['host.name']);
        $this->assertArrayNotHasKey('system.network.io', $this->metrics($body), 'a container only sees its own interface');
    }

    public function test_host_name_setting_wins(): void
    {
        $this->file('.dockerenv', '');
        config(['observera.host.name' => 's001', 'observera.host.role' => 'worker']);

        $a = $this->attrs(HostMetrics::collect($this->root));

        $this->assertSame(['s001', 's001', 'worker'], [$a['host.id'], $a['host.name'], $a['host.role']]);
    }

    public function test_unreadable_proc_sends_nothing(): void
    {
        $this->assertNull(HostMetrics::collect($this->root.'/missing'));
        $this->assertFalse(HostMetrics::supported($this->root.'/missing'));
    }

    public function test_agent_once_reports_unsupported_hosts_without_crashing(): void
    {
        if (HostMetrics::supported()) {
            $this->markTestSkipped('only meaningful where /proc is missing (macOS/Windows CI)');
        }
        $this->artisan('observera:agent', ['--once' => true])->assertFailed();
    }
}

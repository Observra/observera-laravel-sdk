<?php

namespace Observera\Laravel\Instrumentation;

use Observera\Laravel\Transport\Client;

/**
 * Host metrics for the Servers page: CPU, memory, disk, load and network read
 * from /proc, sent as OTLP JSON to /intake/otlp/v1/metrics (the same names the
 * OpenTelemetry hostmetrics receiver uses, so the API maps both identically).
 *
 * Runs inside processes the app already has: every queue worker samples from
 * its loop (Looping event), at most once per INTERVAL. Several workers on one
 * machine all send; the API keeps one sample per host per interval. Hosts
 * without a worker run `php artisan observera:agent`.
 *
 * Linux only. Anything unreadable (macOS, Windows, shared hosting, Lambda)
 * makes collect() return null and nothing is sent — never an error.
 *
 * Counters (cpu time, network bytes) go out cumulative; the API turns them into
 * rates against the previous sample, so no state is kept here.
 */
final class HostMetrics
{
    public const INTERVAL = 15;

    public const PATH = 'intake/otlp/v1/metrics';

    /** Interfaces that would double-count container/bridge traffic on a Docker host. */
    private const SKIP_IFACES = '/^(lo|docker\d*|br-|veth|virbr|cni|flannel|cali|tun|tap)/';

    private static int $last = 0;

    private static ?bool $container = null;

    /** Worker-loop tick: at most one sample per INTERVAL per process. */
    public static function tick(Client $client): void
    {
        if (time() - self::$last < self::INTERVAL) {
            return;
        }
        self::$last = time();
        self::send($client);
    }

    public static function send(Client $client): bool
    {
        $body = self::collect();

        return $body !== null && $client->sendTo(self::PATH, $body);
    }

    /** Linux with a readable /proc, and not a function runtime (Vapor / Lambda). */
    public static function supported(string $root = ''): bool
    {
        return (PHP_OS_FAMILY === 'Linux' || $root !== '')
            && getenv('AWS_LAMBDA_FUNCTION_NAME') === false
            && @is_readable($root.'/proc/stat');
    }

    public static function inContainer(string $root = ''): bool
    {
        if ($root === '' && self::$container !== null) {
            return self::$container;
        }
        $cgroup = (string) @file_get_contents($root.'/proc/1/cgroup');
        $is = file_exists($root.'/.dockerenv')
            || getenv('KUBERNETES_SERVICE_HOST') !== false
            || preg_match('/docker|kubepods|containerd|libpod/', $cgroup) === 1;

        return $root === '' ? (self::$container = $is) : $is;
    }

    /**
     * [host.id, host.name]. OBSERVERA_HOST_NAME wins. Inside a container the
     * hostname is the container id (new on every deploy), so the machine is
     * identified by the kernel boot id, which every container on it shares.
     *
     * @return array{0: string, 1: string}
     */
    public static function identity(string $root = ''): array
    {
        $name = trim((string) config('observera.host.name', ''));
        if ($name !== '') {
            return [$name, $name];
        }
        if (self::inContainer($root)) {
            $boot = trim((string) @file_get_contents($root.'/proc/sys/kernel/random/boot_id'));
            if ($boot !== '') {
                return ['boot:'.$boot, 'host-'.substr(md5($boot), 0, 4)];
            }
        }
        $host = gethostname() ?: 'unknown';

        return [$host, $host];
    }

    /**
     * One OTLP ExportMetricsServiceRequest, or null when /proc is unreadable.
     * `$root` points the /proc reads at a fixture directory in tests.
     *
     * @return array<string, mixed>|null
     */
    public static function collect(string $root = ''): ?array
    {
        if (! self::supported($root)) {
            return null;
        }

        try {
            $now = (string) (int) (microtime(true) * 1e9);
            $point = fn (float $v, array $attrs = []) => [
                'timeUnixNano' => $now,
                'asDouble' => $v,
                'attributes' => array_map(fn ($k, $val) => ['key' => $k, 'value' => ['stringValue' => (string) $val]], array_keys($attrs), $attrs),
            ];
            $gauge = fn (string $name, array $points) => ['name' => $name, 'gauge' => ['dataPoints' => $points]];
            $sum = fn (string $name, array $points, bool $monotonic) => ['name' => $name, 'sum' => ['aggregationTemporality' => 2, 'isMonotonic' => $monotonic, 'dataPoints' => $points]];

            $metrics = [];

            // cpu: per-core cumulative seconds, idle (+iowait) vs busy — /proc/stat is in USER_HZ (100/s)
            $cpu = [];
            foreach ((array) @file($root.'/proc/stat', FILE_IGNORE_NEW_LINES) as $line) {
                if (preg_match('/^(cpu\d+)\s+(.+)$/', (string) $line, $m)) {
                    $f = array_map('floatval', preg_split('/\s+/', trim($m[2])));
                    $idle = ($f[3] ?? 0) + ($f[4] ?? 0);
                    $busy = array_sum(array_slice($f, 0, 8)) - $idle;
                    $cpu[] = $point($idle / 100, ['cpu' => $m[1], 'state' => 'idle']);
                    $cpu[] = $point($busy / 100, ['cpu' => $m[1], 'state' => 'busy']);
                }
            }
            if ($cpu !== []) {
                $metrics[] = $sum('system.cpu.time', $cpu, true);
            }

            // memory: used = total - available (what `free` calls available)
            $mem = [];
            foreach ((array) @file($root.'/proc/meminfo', FILE_IGNORE_NEW_LINES) as $line) {
                if (preg_match('/^(MemTotal|MemAvailable):\s+(\d+)/', (string) $line, $m)) {
                    $mem[$m[1]] = (float) $m[2] * 1024;
                }
            }
            if (isset($mem['MemTotal'], $mem['MemAvailable'])) {
                $metrics[] = $sum('system.memory.usage', [
                    $point($mem['MemTotal'] - $mem['MemAvailable'], ['state' => 'used']),
                    $point($mem['MemAvailable'], ['state' => 'free']),
                ], false);
            }

            // disk: the root filesystem
            $total = @disk_total_space('/');
            $free = @disk_free_space('/');
            if ($total && $free !== false) {
                $metrics[] = $sum('system.filesystem.usage', [
                    $point($total - $free, ['mountpoint' => '/', 'state' => 'used']),
                    $point($free, ['mountpoint' => '/', 'state' => 'free']),
                ], false);
            }

            $load = $root === '' ? sys_getloadavg() : array_map('floatval', explode(' ', (string) @file_get_contents($root.'/proc/loadavg')));
            if (is_array($load) && count($load) >= 2) {
                $metrics[] = $gauge('system.cpu.load_average.1m', [$point((float) $load[0])]);
                $metrics[] = $gauge('system.cpu.load_average.5m', [$point((float) $load[1])]);
            }

            // network: a container only sees its own interface, which is not the
            // host's traffic — leave it out there rather than report a wrong number
            if (! self::inContainer($root)) {
                $net = [];
                foreach (array_slice((array) @file($root.'/proc/net/dev', FILE_IGNORE_NEW_LINES), 2) as $line) {
                    [$iface, $rest] = array_pad(explode(':', (string) $line, 2), 2, '');
                    $iface = trim($iface);
                    if ($iface === '' || preg_match(self::SKIP_IFACES, $iface)) {
                        continue;
                    }
                    $f = preg_split('/\s+/', trim($rest));
                    $net[] = $point((float) ($f[0] ?? 0), ['device' => $iface, 'direction' => 'receive']);
                    $net[] = $point((float) ($f[8] ?? 0), ['device' => $iface, 'direction' => 'transmit']);
                }
                if ($net !== []) {
                    $metrics[] = $sum('system.network.io', $net, true);
                }
            }

            if ($metrics === []) {
                return null;
            }

            [$id, $name] = self::identity($root);
            $attrs = array_filter([
                'host.id' => $id,
                'host.name' => $name,
                'host.role' => (string) config('observera.host.role', ''),
                'os.type' => 'linux',
                'service.name' => (string) config('app.name', ''),
                'observera.agent.version' => self::version(),
            ], fn ($v) => $v !== '');

            return ['resourceMetrics' => [[
                'resource' => ['attributes' => array_map(fn ($k, $v) => ['key' => $k, 'value' => ['stringValue' => $v]], array_keys($attrs), $attrs)],
                'scopeMetrics' => [['scope' => ['name' => 'observera-laravel'], 'metrics' => $metrics]],
            ]]];
        } catch (\Throwable) {
            return null; // telemetry must never break the worker
        }
    }

    private static function version(): string
    {
        try {
            return substr((string) \Composer\InstalledVersions::getPrettyVersion('observera/laravel'), 0, 16);
        } catch (\Throwable) {
            return '';
        }
    }
}

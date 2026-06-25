<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Bridge\Symfony\Health;

use Symfony\Component\Process\Process;

/**
 * Read-only host metrics (no app DB probing). Every probe degrades gracefully:
 * missing data yields '', null or [] so the UI can skip the corresponding section.
 * Adapted from a larger project's SystemHealthService.
 */
final class SystemHealthService implements HealthProvider
{
    /** @param list<string> $monitoredServices systemd units to report via `systemctl is-active` */
    public function __construct(
        private readonly array $monitoredServices,
        private readonly VnstatService $vnstat,
    ) {
    }

    public function getData(): array
    {
        return [
            'model' => $this->getModel(),
            'uptime' => $this->getUptime(),
            'temperature' => $this->getTemperature(),
            'throttle' => $this->getThrottle(),
            'load' => $this->getLoadAverage(),
            'memory' => $this->getMemory(),
            'disks' => $this->getDisks(),
            'interfaces' => $this->getNetworkInterfaces(),
            'docker' => $this->getDockerContainers(),
            'services' => $this->getServices(),
            'processes' => $this->getTopProcesses(),
            'network_usage' => $this->vnstat->getData(),
        ];
    }

    /**
     * Top processes by CPU (snapshot via `ps`). Empty when `ps` is unavailable.
     *
     * @return list<array<string, mixed>>
     */
    private function getTopProcesses(int $limit = 20): array
    {
        $process = new Process(['ps', '-eo', 'pid,user,pcpu,pmem,rss,comm', '--sort=-pcpu']);
        $process->setTimeout(5);
        $process->run();
        if (!$process->isSuccessful()) {
            return [];
        }

        $lines = explode("\n", trim($process->getOutput()));
        array_shift($lines); // header
        $rows = [];

        foreach ($lines as $line) {
            $parts = preg_split('/\s+/', trim($line), 6);
            if (!\is_array($parts) || \count($parts) < 6) {
                continue;
            }
            [$pid, $user, $cpu, $mem, $rss, $comm] = $parts;
            $cpuPct = (float) $cpu;
            $rows[] = [
                'pid' => (int) $pid,
                'user' => $user,
                'cpu' => $cpuPct,
                'mem' => (float) $mem,
                'rss' => Bytes::format((int) $rss * 1024),
                'command' => $comm,
                'color' => $cpuPct >= 80 ? 'danger' : ($cpuPct >= 40 ? 'warning' : 'secondary'),
            ];
            if (\count($rows) >= $limit) {
                break;
            }
        }

        return $rows;
    }

    private function getModel(): string
    {
        $content = @file_get_contents('/proc/cpuinfo');
        if (false !== $content && preg_match('/^Model\s*:\s*(.+)$/m', $content, $m)) {
            return trim($m[1]);
        }

        return \PHP_OS_FAMILY;
    }

    /** @return array<string, mixed>|null */
    private function getUptime(): ?array
    {
        $content = @file_get_contents('/proc/uptime');
        if (false === $content) {
            return null;
        }

        $seconds = (int) explode(' ', $content)[0];
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        $parts = [];
        if ($days > 0) {
            $parts[] = $days.'j';
        }
        if ($hours > 0) {
            $parts[] = $hours.'h';
        }
        $parts[] = $minutes.'min';

        return ['seconds' => $seconds, 'human' => implode(' ', $parts)];
    }

    /** @return array<string, mixed>|null */
    private function getTemperature(): ?array
    {
        $process = new Process(['vcgencmd', 'measure_temp']);
        $process->setTimeout(5);
        $process->run();
        if ($process->isSuccessful() && preg_match('/temp=([\d.]+)/', $process->getOutput(), $m)) {
            return $this->buildTempResult((float) $m[1]);
        }

        $raw = @file_get_contents('/sys/class/thermal/thermal_zone0/temp');
        if (false !== $raw && '' !== trim($raw)) {
            return $this->buildTempResult(round((int) trim($raw) / 1000, 1));
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function buildTempResult(float $temp): array
    {
        return [
            'celsius' => $temp,
            'color' => $temp < 60 ? 'success' : ($temp < 70 ? 'warning' : 'danger'),
            'label' => $temp.' °C',
        ];
    }

    /** @return array<string, mixed>|null */
    private function getThrottle(): ?array
    {
        $process = new Process(['vcgencmd', 'get_throttled']);
        $process->setTimeout(5);
        $process->run();
        if (!$process->isSuccessful() || !preg_match('/throttled=(0x[0-9a-fA-F]+)/', $process->getOutput(), $m)) {
            return null;
        }

        $val = (int) hexdec($m[1]);
        $flags = [
            0x1 => 'Under-voltage', 0x2 => 'Frequency capped', 0x4 => 'Throttled', 0x8 => 'Soft temp limit',
            0x10000 => 'Under-voltage (since boot)', 0x20000 => 'Frequency capped (since boot)',
            0x40000 => 'Throttled (since boot)', 0x80000 => 'Soft temp limit (since boot)',
        ];
        $issues = [];
        foreach ($flags as $bit => $label) {
            if ($val & $bit) {
                $issues[] = $label;
            }
        }

        return ['raw' => $m[1], 'ok' => 0 === $val, 'issues' => $issues];
    }

    /** @return array<string, mixed>|null */
    private function getLoadAverage(): ?array
    {
        $content = @file_get_contents('/proc/loadavg');
        if (false === $content) {
            return null;
        }

        $parts = explode(' ', trim($content));
        $cpuCount = $this->getCpuCount();
        $load1 = (float) $parts[0];

        return [
            '1m' => $load1,
            '5m' => (float) ($parts[1] ?? 0),
            '15m' => (float) ($parts[2] ?? 0),
            'cpu_count' => $cpuCount,
            'pct_1m' => (int) min(round($load1 / $cpuCount * 100), 100),
            'color' => $load1 > $cpuCount ? 'danger' : ($load1 > $cpuCount * 0.7 ? 'warning' : 'success'),
        ];
    }

    private function getCpuCount(): int
    {
        $content = @file_get_contents('/proc/cpuinfo');
        if (false !== $content) {
            $matches = preg_match_all('/^processor\s*:/m', $content);

            return max(1, false !== $matches ? $matches : 1);
        }

        return 1;
    }

    /** @return array<string, mixed> */
    private function getMemory(): array
    {
        $content = @file_get_contents('/proc/meminfo');
        if (false === $content) {
            return [];
        }

        $values = [];
        foreach (explode("\n", $content) as $line) {
            if (preg_match('/^(\w+):\s+(\d+)/', $line, $m)) {
                $values[$m[1]] = (int) $m[2];
            }
        }

        $total = $values['MemTotal'] ?? 0;
        if ($total <= 0) {
            return [];
        }
        $available = $values['MemAvailable'] ?? 0;
        $used = $total - $available;
        $usedPct = (int) round($used / $total * 100);

        return [
            'total' => Bytes::format($total * 1024),
            'used' => Bytes::format($used * 1024),
            'available' => Bytes::format($available * 1024),
            'cached' => Bytes::format((($values['Cached'] ?? 0) + ($values['Buffers'] ?? 0)) * 1024),
            'used_pct' => $usedPct,
            'color' => $usedPct < 70 ? 'success' : ($usedPct < 85 ? 'warning' : 'danger'),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function getDisks(): array
    {
        $process = new Process(['df', '-B1', '--output=source,fstype,size,used,avail,pcent,target']);
        $process->setTimeout(5);
        $process->run();
        if (!$process->isSuccessful()) {
            return [];
        }

        $lines = explode("\n", trim($process->getOutput()));
        array_shift($lines);
        $skipFstypes = ['tmpfs', 'devtmpfs', 'overlay', 'udev', 'squashfs'];
        $disks = [];

        foreach ($lines as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (!\is_array($parts) || \count($parts) < 7) {
                continue;
            }
            [$source, $fstype, $size, $used, $avail, $pct, $mount] = $parts;
            if (\in_array($fstype, $skipFstypes, true) && 'log2ram' !== $source) {
                continue;
            }
            $pctNum = (int) rtrim($pct, '%');
            $disks[] = [
                'source' => $source,
                'mount' => $mount,
                'fstype' => $fstype,
                'size' => Bytes::format((int) $size),
                'used' => Bytes::format((int) $used),
                'avail' => Bytes::format((int) $avail),
                'used_pct' => $pctNum,
                'color' => $pctNum < 70 ? 'success' : ($pctNum < 85 ? 'warning' : 'danger'),
            ];
        }

        return $disks;
    }

    /** @return list<array<string, mixed>> */
    private function getNetworkInterfaces(): array
    {
        $process = new Process(['ip', '-j', 'addr', 'show']);
        $process->setTimeout(5);
        $process->run();
        if (!$process->isSuccessful()) {
            return [];
        }

        $data = json_decode($process->getOutput(), true);
        if (!\is_array($data)) {
            return [];
        }

        $skipNames = ['lo'];
        $skipPrefixes = ['veth', 'br-'];
        $result = [];

        foreach ($data as $iface) {
            if (!\is_array($iface)) {
                continue;
            }
            $name = (string) ($iface['ifname'] ?? '');
            if (\in_array($name, $skipNames, true)) {
                continue;
            }
            foreach ($skipPrefixes as $prefix) {
                if (str_starts_with($name, $prefix)) {
                    continue 2;
                }
            }

            $ips = [];
            foreach ($iface['addr_info'] ?? [] as $addr) {
                if (\is_array($addr) && 'inet' === ($addr['family'] ?? null)) {
                    $ips[] = $addr['local'].'/'.$addr['prefixlen'];
                }
            }
            $state = (string) ($iface['operstate'] ?? 'UNKNOWN');
            $result[] = [
                'name' => $name,
                'state' => $state,
                'color' => 'UP' === $state ? 'success' : ('DOWN' === $state ? 'danger' : 'secondary'),
                'ips' => $ips,
                'mac' => (string) ($iface['address'] ?? ''),
            ];
        }

        return $result;
    }

    /** @return list<array<string, mixed>> */
    private function getDockerContainers(): array
    {
        $ps = new Process(['docker', 'ps', '-a', '--format', '{{json .}}']);
        $ps->setTimeout(10);
        $ps->run();
        if (!$ps->isSuccessful()) {
            return [];
        }

        $containers = [];
        foreach (explode("\n", trim($ps->getOutput())) as $line) {
            $line = trim($line);
            if ('' === $line) {
                continue;
            }
            $c = json_decode($line, true);
            if (!\is_array($c)) {
                continue;
            }
            $name = (string) ($c['Names'] ?? ($c['ID'] ?? 'unknown'));
            $containers[$name] = [
                'name' => $name,
                'image' => (string) ($c['Image'] ?? ''),
                'status' => (string) ($c['Status'] ?? ''),
                'running' => str_starts_with((string) ($c['Status'] ?? ''), 'Up'),
                'cpu' => null,
                'mem' => null,
            ];
        }

        // Live CPU%/memory (usage / allocated limit), merged by name when available.
        $stats = new Process(['docker', 'stats', '--no-stream', '--format', '{{json .}}']);
        $stats->setTimeout(15);
        $stats->run();
        if ($stats->isSuccessful()) {
            foreach (explode("\n", trim($stats->getOutput())) as $line) {
                $line = trim($line);
                if ('' === $line) {
                    continue;
                }
                $s = json_decode($line, true);
                if (!\is_array($s)) {
                    continue;
                }
                $name = (string) ($s['Name'] ?? '');
                if (isset($containers[$name])) {
                    $containers[$name]['cpu'] = $s['CPUPerc'] ?? null;
                    $containers[$name]['mem'] = $s['MemUsage'] ?? null;
                }
            }
        }

        return array_values($containers);
    }

    /** @return list<array<string, mixed>> */
    private function getServices(): array
    {
        $result = [];
        foreach ($this->monitoredServices as $service) {
            $proc = new Process(['systemctl', 'is-active', $service]);
            $proc->setTimeout(5);
            $proc->run();
            $state = trim($proc->getOutput());
            $result[] = [
                'name' => $service,
                'state' => '' !== $state ? $state : 'unknown',
                'color' => match ($state) {
                    'active' => 'success',
                    'failed' => 'danger',
                    'inactive' => 'secondary',
                    default => 'warning',
                },
            ];
        }

        return $result;
    }
}

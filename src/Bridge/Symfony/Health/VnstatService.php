<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Bridge\Symfony\Health;

use Symfony\Component\Process\Process;

/**
 * Network usage via `vnstat --json`. Returns null when vnstat is absent/unavailable
 * so the UI shows nothing. Adapted (trimmed) from a larger project service.
 */
final class VnstatService
{
    /**
     * @return array<string, mixed>|null
     */
    public function getData(): ?array
    {
        $process = new Process(['vnstat', '--json']);
        $process->setTimeout(5);
        $process->run();

        if (!$process->isSuccessful()) {
            return null;
        }

        $data = json_decode($process->getOutput(), true);
        if (!\is_array($data) || !\is_array($data['interfaces'] ?? null)) {
            return null;
        }

        $interfaces = [];
        foreach ($data['interfaces'] as $iface) {
            if (!\is_array($iface)) {
                continue;
            }
            $traffic = \is_array($iface['traffic'] ?? null) ? $iface['traffic'] : [];
            $total = \is_array($traffic['total'] ?? null) ? $traffic['total'] : ['rx' => 0, 'tx' => 0];
            $rx = (int) ($total['rx'] ?? 0);
            $tx = (int) ($total['tx'] ?? 0);

            $interfaces[] = [
                'name' => (string) ($iface['name'] ?? 'unknown'),
                'rx_fmt' => Bytes::format($rx),
                'tx_fmt' => Bytes::format($tx),
                'total_fmt' => Bytes::format($rx + $tx),
                'month' => $this->monthEstimate(\is_array($traffic['month'] ?? null) ? $traffic['month'] : []),
            ];
        }

        if ([] === $interfaces) {
            return null;
        }

        return [
            'version' => $data['vnstatversion'] ?? null,
            'interfaces' => $interfaces,
        ];
    }

    /**
     * Current-month total + linear projection to month-end.
     *
     * @param array<int, array<string, mixed>> $monthPeriods
     *
     * @return array<string, mixed>|null
     */
    private function monthEstimate(array $monthPeriods): ?array
    {
        $now = new \DateTimeImmutable();
        $year = (int) $now->format('Y');
        $month = (int) $now->format('n');
        $dayOfMonth = (int) $now->format('j');
        $daysInMonth = (int) $now->format('t');

        foreach ($monthPeriods as $entry) {
            $date = \is_array($entry['date'] ?? null) ? $entry['date'] : [];
            if ((int) ($date['year'] ?? 0) !== $year || (int) ($date['month'] ?? 0) !== $month) {
                continue;
            }

            $current = (int) ($entry['rx'] ?? 0) + (int) ($entry['tx'] ?? 0);
            $estimated = (int) round($current / max($dayOfMonth, 1) * $daysInMonth);

            return [
                'total_fmt' => Bytes::format($current),
                'est_total_fmt' => Bytes::format($estimated),
                'progress_pct' => (int) round($dayOfMonth / $daysInMonth * 100),
            ];
        }

        return null;
    }
}

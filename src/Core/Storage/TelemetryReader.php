<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Core\Storage;

use Aleblanc\LogUi\Core\Model\Profile;

/**
 * Reads LogUI telemetry back from the host's existing log file.
 *
 * Each profiled request/command is written into the normal log stream as one line carrying a
 * `LOGUI@{json}` sentinel (see the bridge's TelemetryLogger). This reader scans the tail of the
 * file (bounded memory — safe on multi-GB logs), extracts the JSON after the sentinel up to the
 * last brace on the line (robust to nested JSON), and rebuilds a Profile from it.
 */
final class TelemetryReader
{
    public const SENTINEL = 'LOGUI@';
    public const DEFAULT_TAIL_BYTES = 5_000_000;

    /**
     * Reads several files as one history, newest file first, sharing a single byte budget.
     *
     * The budget is shared rather than per-file on purpose: with 30 days of rotation, a per-file
     * tail would load 30 × 5 MB into memory to show a page of 100 rows. Spending it newest-first
     * means the recent history is complete and the tail of the record is what gets dropped —
     * which is the half nobody is looking at.
     *
     * @param list<string> $paths newest first (see {@see TelemetryFiles::all()})
     *
     * @return list<Profile> oldest first
     */
    public function readAll(array $paths, int $maxBytes = self::DEFAULT_TAIL_BYTES): array
    {
        $profiles = [];
        $budget = $maxBytes;

        foreach ($paths as $path) {
            if ($budget <= 0) {
                break;
            }

            $profiles = [...$this->read($path, $budget), ...$profiles];
            $budget -= is_file($path) ? (int) filesize($path) : 0;
        }

        return $profiles;
    }

    /** @return list<Profile> in file order (oldest first) */
    public function read(string $path, int $maxBytes = self::DEFAULT_TAIL_BYTES): array
    {
        if (!is_file($path)) {
            return [];
        }

        $handle = fopen($path, 'rb');
        if (false === $handle) {
            return [];
        }

        $profiles = [];
        try {
            $size = fstat($handle)['size'] ?? 0;
            if ($size > $maxBytes) {
                fseek($handle, $size - $maxBytes);
                fgets($handle); // drop the partial first line of the window
            }

            $markerLen = \strlen(self::SENTINEL);
            while (false !== ($line = fgets($handle))) {
                $pos = strpos($line, self::SENTINEL);
                if (false === $pos) {
                    continue;
                }
                $start = $pos + $markerLen;
                $end = strrpos($line, '}');
                if (false === $end || $end < $start) {
                    continue;
                }
                $data = json_decode(substr($line, $start, $end - $start + 1), true);
                if (\is_array($data)) {
                    $profiles[] = Profile::fromArray($data);
                }
            }
        } finally {
            fclose($handle);
        }

        return $profiles;
    }
}

<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Bridge\Symfony\Telemetry;

use Aleblanc\LogUi\Core\Model\Profile;
use Aleblanc\LogUi\Core\Storage\TelemetryReader;

/**
 * Appends one telemetry line per profiled request/command to LogUI's telemetry file — a single
 * `LOGUI@{json}` sentinel line that TelemetryReader parses back to rebuild the request list.
 *
 * The line is written directly to the file (not through the host logger) so capture is immune to
 * the host's Monolog routing — in particular `fingers_crossed` (which buffers and drops non-error
 * records) and `rotating_file` naming, both of which otherwise swallow telemetry in production.
 * Writes are best-effort: any I/O failure is swallowed so capture never disrupts the host app.
 */
final class TelemetryLogger
{
    public function __construct(private readonly string $telemetryFile)
    {
    }

    public function log(Profile $profile): void
    {
        // The line carries the full capture — metrics AND the request's own log records (all channels,
        // bounded by max_records_per_profile) — so the detail can show them all, self-contained.
        $json = json_encode($profile->toArray(), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR);
        if (false === $json) {
            return;
        }

        $dir = \dirname($this->telemetryFile);
        if (!is_dir($dir) && !mkdir($dir, 0o777, true) && !is_dir($dir)) {
            return;
        }

        $handle = fopen($this->telemetryFile, 'a');
        if (false === $handle) {
            return;
        }

        try {
            if (flock($handle, \LOCK_EX)) {
                fwrite($handle, TelemetryReader::SENTINEL.$json."\n");
                fflush($handle);
                flock($handle, \LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }
}

<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Bridge\Symfony\Telemetry;

use Aleblanc\LogUi\Core\Model\Profile;
use Aleblanc\LogUi\Core\Storage\TelemetryFiles;
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
    private ?string $lastFile = null;

    public function __construct(
        private readonly string $telemetryFile,
        private readonly ?TelemetryFiles $files = null,
    ) {
    }

    public function log(Profile $profile): void
    {
        // The line carries the full capture — metrics AND the request's own log records (all channels,
        // bounded by max_records_per_profile) — so the detail can show them all, self-contained.
        $json = json_encode($profile->toArray(), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR);
        if (false === $json) {
            return;
        }

        $file = $this->currentFile();

        $dir = \dirname($file);
        if (!is_dir($dir) && !mkdir($dir, 0o777, true) && !is_dir($dir)) {
            return;
        }

        $handle = fopen($file, 'a');
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

    /**
     * The file to append to, pruning old ones the first time a new day (or month) is seen.
     *
     * Pruning here rather than on a schedule keeps the bundle free of cron: the only moment the
     * set of files can grow is the moment one is created, so that is when it is trimmed. The
     * `$lastFile` guard means the directory is scanned once per rotation, not once per request.
     */
    private function currentFile(): string
    {
        if (null === $this->files) {
            return $this->telemetryFile;
        }

        $file = $this->files->current();
        if ($file !== $this->lastFile) {
            $this->lastFile = $file;
            $this->files->prune();
        }

        return $file;
    }
}

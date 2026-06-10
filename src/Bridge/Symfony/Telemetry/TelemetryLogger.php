<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Bridge\Symfony\Telemetry;

use Aleblanc\LogUi\Core\Model\Profile;
use Aleblanc\LogUi\Core\Storage\TelemetryReader;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Writes one telemetry line per profiled request/command into the host's EXISTING log stream
 * (no separate store). The line carries a `LOGUI@{json}` sentinel that TelemetryReader parses
 * back to rebuild the request list. Severity drives the log level so it also reads naturally
 * as a normal log entry.
 */
final class TelemetryLogger
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function log(Profile $profile): void
    {
        // The line carries the full capture — metrics AND the request's own log records (all channels,
        // bounded by max_records_per_profile) — so the detail can show them all, self-contained, with
        // no separate store and no dependency on which channels the host persists to file.
        $json = json_encode($profile->toArray(), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR);
        if (false === $json) {
            return;
        }

        $this->logger->log($this->level($profile), TelemetryReader::SENTINEL.$json);
    }

    private function level(Profile $profile): string
    {
        if (($profile->levels['critical'] ?? 0) + ($profile->levels['error'] ?? 0) > 0
            || (null !== $profile->status && $profile->status >= 500)) {
            return LogLevel::ERROR;
        }

        if (($profile->levels['warning'] ?? 0) > 0
            || (null !== $profile->status && $profile->status >= 400)) {
            return LogLevel::WARNING;
        }

        return LogLevel::INFO;
    }
}

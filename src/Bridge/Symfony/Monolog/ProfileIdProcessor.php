<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Bridge\Symfony\Monolog;

use Aleblanc\LogUi\Bridge\Symfony\Capture\CurrentProfile;
use Aleblanc\LogUi\Core\Storage\TelemetryReader;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Stamps every Monolog record written during a profile with `extra.logui_id` — the id of the
 * request or command that wrote it. That is what lets the explorer pivot from a log line to its
 * request (and vice versa): the id is searchable through {@see \Aleblanc\LogUi\Core\Query\Grep}.
 *
 * Null-safe like the rest of capture: a record emitted with no active profile, or one that already
 * carries an id, or the telemetry line itself, is returned untouched.
 */
final class ProfileIdProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly CurrentProfile $current,
        private readonly bool $enabled = true,
    ) {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $id = $this->enabled ? $this->current->id() : null;
        if (null === $id || isset($record->extra['logui_id']) || str_starts_with($record->message, TelemetryReader::SENTINEL)) {
            return $record;
        }

        return $record->with(extra: $record->extra + ['logui_id' => $id]);
    }
}

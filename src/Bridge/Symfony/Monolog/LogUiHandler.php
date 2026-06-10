<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Bridge\Symfony\Monolog;

use Aleblanc\LogUi\Bridge\Symfony\Capture\CurrentProfile;
use Aleblanc\LogUi\Core\Storage\TelemetryReader;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * Monolog handler that mirrors every record into the active LogUI profile.
 * Captures all levels (Debug) and always bubbles so it never disturbs other handlers.
 *
 * It is auto-wired onto every channel by LogUiBundle::prependExtension(). The $enabled
 * flag (log_ui.capture_monolog) lets the host turn capture off at runtime — e.g. in prod
 * via an env var — without the handler having to be removed from the logger stack.
 */
final class LogUiHandler extends AbstractProcessingHandler
{
    public function __construct(
        private readonly CurrentProfile $current,
        private readonly bool $enabled = true,
    ) {
        parent::__construct(Level::Debug, true);
    }

    protected function write(LogRecord $record): void
    {
        // Capture disabled, or this is our own telemetry line being written back through
        // the logger at terminate — never mirror it into the profile (would be noise/recursion).
        if (!$this->enabled || str_starts_with($record->message, TelemetryReader::SENTINEL)) {
            return;
        }

        $this->current->addRecord(
            strtolower($record->level->getName()),
            $record->channel,
            $record->message,
            $record->context,
        );
    }
}

<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Bridge\Symfony\Monolog;

use Aleblanc\LogUi\Bridge\Symfony\Capture\CurrentProfile;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * Monolog handler that mirrors every record into the active LogUI profile.
 * Captures all levels (Debug) and always bubbles so it never disturbs other handlers.
 */
final class LogUiHandler extends AbstractProcessingHandler
{
    public function __construct(private readonly CurrentProfile $current)
    {
        parent::__construct(Level::Debug, true);
    }

    protected function write(LogRecord $record): void
    {
        $this->current->addRecord(
            strtolower($record->level->getName()),
            $record->channel,
            $record->message,
            $record->context,
        );
    }
}

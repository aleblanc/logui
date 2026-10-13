<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Bridge\Symfony\Capture;

use Aleblanc\LogUi\Bridge\Symfony\Telemetry\TelemetryLogger;
use Aleblanc\LogUi\Core\Capture\IdGenerator;
use Aleblanc\LogUi\Core\Capture\ProfileContextFactory;
use Aleblanc\LogUi\Core\Capture\RandomIdGenerator;
use Aleblanc\LogUi\Core\Model\ProfileType;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class CommandProfilerListener implements EventSubscriberInterface
{
    private IdGenerator $ids;

    /**
     * @param list<string> $ignore command names never profiled; shell wildcards allowed
     */
    public function __construct(
        private readonly CurrentProfile $current,
        private readonly ProfileContextFactory $factory,
        private readonly TelemetryLogger $telemetry,
        ?IdGenerator $ids = null,
        private readonly array $ignore = [],
        private readonly bool $enabled = true,
    ) {
        $this->ids = $ids ?? new RandomIdGenerator();
    }

    /** @return array<string,string> */
    public static function getSubscribedEvents(): array
    {
        return [
            ConsoleEvents::COMMAND => 'onConsoleCommand',
            ConsoleEvents::ERROR => 'onConsoleError',
            ConsoleEvents::TERMINATE => 'onConsoleTerminate',
        ];
    }

    public function onConsoleCommand(ConsoleCommandEvent $event): void
    {
        $name = $event->getCommand()?->getName() ?? '(closure)';

        if (!$this->enabled || $this->ignored($name)) {
            return;
        }

        $this->current->begin($this->factory->create($this->ids->generate(), ProfileType::Cli, $name));
    }

    /**
     * A command that runs every minute writes a telemetry line every minute, whether or not it
     * did anything — a scheduler tick was 98 % of one real production log file. HTTP requests
     * have had `ignore_paths` since the start; this is the same escape hatch for the console.
     */
    private function ignored(string $name): bool
    {
        foreach ($this->ignore as $pattern) {
            if ($name === $pattern || fnmatch($pattern, $name)) {
                return true;
            }
        }

        return false;
    }

    public function onConsoleError(ConsoleErrorEvent $event): void
    {
        $this->current->setException($event->getError());
    }

    public function onConsoleTerminate(ConsoleTerminateEvent $event): void
    {
        if (!$this->current->isActive()) {
            return;
        }
        try {
            $profile = $this->current->finish($event->getExitCode());
            if (null !== $profile) {
                $this->telemetry->log($profile);
            }
        } catch (\Throwable) {
            // never break the command
        }
    }
}

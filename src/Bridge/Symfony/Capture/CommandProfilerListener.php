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

    public function __construct(
        private readonly CurrentProfile $current,
        private readonly ProfileContextFactory $factory,
        private readonly TelemetryLogger $telemetry,
        ?IdGenerator $ids = null,
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
        $this->current->begin($this->factory->create($this->ids->generate(), ProfileType::Cli, $name));
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

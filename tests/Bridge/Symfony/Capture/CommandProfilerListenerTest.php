<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Tests\Bridge\Symfony\Capture;

use Aleblanc\LogUi\Bridge\Symfony\Capture\CommandProfilerListener;
use Aleblanc\LogUi\Bridge\Symfony\Capture\CurrentProfile;
use Aleblanc\LogUi\Bridge\Symfony\Telemetry\TelemetryLogger;
use Aleblanc\LogUi\Core\Capture\ProfileContextFactory;
use Aleblanc\LogUi\Core\Capture\Redactor;
use Aleblanc\LogUi\Core\Model\ProfileType;
use Aleblanc\LogUi\Core\Stats\MemoryProbe;
use Aleblanc\LogUi\Core\Storage\TelemetryReader;
use Aleblanc\LogUi\Tests\Support\FixedClock;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class CommandProfilerListenerTest extends TestCase
{
    private string $logFile;

    protected function setUp(): void
    {
        $this->logFile = sys_get_temp_dir().'/logui-cmd-'.uniqid().'.log';
    }

    protected function tearDown(): void
    {
        @unlink($this->logFile);
    }

    public function test_command_is_profiled_and_logged(): void
    {
        $clock = new FixedClock(new \DateTimeImmutable('2026-06-10T14:00:00+00:00'));
        $current = new CurrentProfile();
        $factory = new ProfileContextFactory($clock, new Redactor([]), new MemoryProbe(), 1000, 50.0);
        $telemetry = new TelemetryLogger($this->logFile);
        $listener = new CommandProfilerListener($current, $factory, $telemetry);

        $command = new Command('app:demo');
        $input = new ArrayInput([]);
        $output = new BufferedOutput();

        $listener->onConsoleCommand(new ConsoleCommandEvent($command, $input, $output));
        $current->addRecord('info', 'app', 'working', []);
        $listener->onConsoleTerminate(new ConsoleTerminateEvent($command, $input, $output, 0));

        $profiles = (new TelemetryReader())->read($this->logFile);
        self::assertCount(1, $profiles);
        self::assertSame(ProfileType::Cli, $profiles[0]->type);
        self::assertSame('app:demo', $profiles[0]->label);
        self::assertSame(0, $profiles[0]->status);
    }
}

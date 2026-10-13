<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Tests\Bridge\Symfony\Capture;

use Aleblanc\LogUi\Bridge\Symfony\Capture\CommandProfilerListener;
use Aleblanc\LogUi\Bridge\Symfony\Capture\CurrentProfile;
use Aleblanc\LogUi\Bridge\Symfony\Telemetry\TelemetryLogger;
use Aleblanc\LogUi\Core\Capture\ProfileContextFactory;
use Aleblanc\LogUi\Core\Capture\Redactor;
use Aleblanc\LogUi\Core\Stats\MemoryProbe;
use Aleblanc\LogUi\Core\Stats\SystemClock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * A command that runs every minute writes a telemetry line every minute, whether or not it did
 * anything. On one real production install a scheduler tick was **98 % of the log file**, which
 * is how it reached a gigabyte. HTTP requests have had `ignore_paths` from the start; this is the
 * same escape hatch for the console.
 */
final class CommandIgnoreTest extends TestCase
{
    /** @param list<string> $ignore */
    private function begins(string $command, array $ignore): bool
    {
        $current = new CurrentProfile();
        $listener = new CommandProfilerListener(
            $current,
            new ProfileContextFactory(new SystemClock(), new Redactor([]), new MemoryProbe(), 100, 50.0),
            // Real logger pointed at nowhere: the listener never reaches it here, and the class
            // is final so there is nothing to double.
            new TelemetryLogger('/dev/null'),
            null,
            $ignore,
        );

        $listener->onConsoleCommand(new ConsoleCommandEvent(
            new Command($command),
            new ArrayInput([]),
            new NullOutput(),
        ));

        return $current->isActive();
    }

    public function test_commands_are_profiled_by_default(): void
    {
        self::assertTrue($this->begins('app:import', []));
    }

    public function test_an_exact_name_is_ignored(): void
    {
        self::assertFalse($this->begins('scheduler:run', ['scheduler:run']));
    }

    public function test_other_commands_keep_being_profiled(): void
    {
        self::assertTrue($this->begins('app:import', ['scheduler:run']));
    }

    #[DataProvider('patterns')]
    public function test_wildcards_match_the_way_a_shell_would(string $command, string $pattern, bool $ignored): void
    {
        self::assertSame(!$ignored, $this->begins($command, [$pattern]));
    }

    /** @return \Generator<string,array{string,string,bool}> */
    public static function patterns(): \Generator
    {
        yield 'prefix wildcard' => ['scheduler:run', 'scheduler:*', true];
        yield 'prefix, other family' => ['app:import', 'scheduler:*', false];
        yield 'suffix wildcard' => ['app:import:live', '*:live', true];
        yield 'single char' => ['cache:clear', 'cache:clea?', true];
        yield 'bare star' => ['anything', '*', true];
        yield 'no accidental prefix' => ['scheduler:runner', 'scheduler:run', false];
    }

    public function test_several_patterns_are_ored(): void
    {
        self::assertFalse($this->begins('messenger:consume', ['scheduler:*', 'messenger:consume']));
        self::assertTrue($this->begins('app:import', ['scheduler:*', 'messenger:consume']));
    }
}

<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Tests\Bridge\Symfony\Command;

use Aleblanc\LogUi\Bridge\Symfony\Command\CheckCommand;
use Aleblanc\LogUi\Bridge\Symfony\Log\RawLogSources;
use Aleblanc\LogUi\Bridge\Symfony\Monolog\HandlerPathDiscovery;
use Aleblanc\LogUi\Core\Query\LogExplorer;
use Aleblanc\LogUi\Core\Storage\MergedLogReader;
use Aleblanc\LogUi\Core\Storage\PlainLogReader;
use Aleblanc\LogUi\Core\Time\TimestampParser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class CheckCommandTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir().'/logui-check-'.uniqid().'.log';
        $now = time();
        $lines = [];
        for ($i = 0; $i < 3; ++$i) {
            $lines[] = sprintf('[%s] app.ERROR: boom %d', gmdate('Y-m-d\TH:i:sP', $now - $i), $i);
        }
        file_put_contents($this->file, implode("\n", $lines)."\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    private function tester(): CommandTester
    {
        $command = new CheckCommand(
            new RawLogSources(new HandlerPathDiscovery(), [], [$this->file]),
            new LogExplorer(new MergedLogReader(new PlainLogReader(new TimestampParser('UTC')))),
        );

        return new CommandTester($command);
    }

    public function test_exits_non_zero_when_the_threshold_is_reached(): void
    {
        $tester = $this->tester();
        $exit = $tester->execute(['query' => '{level="error"}', '--min' => '3']);

        self::assertSame(1, $exit);
        self::assertStringContainsString('3', $tester->getDisplay());
    }

    public function test_exits_zero_below_the_threshold(): void
    {
        self::assertSame(0, $this->tester()->execute(['query' => '{level="error"}', '--min' => '4']));
    }

    public function test_json_format_prints_the_total(): void
    {
        $tester = $this->tester();
        $tester->execute(['query' => '{level="error"}', '--min' => '3', '--format' => 'json']);

        self::assertStringContainsString('"total":3', $tester->getDisplay());
    }

    public function test_a_bad_since_exits_two(): void
    {
        $tester = $this->tester();

        self::assertSame(2, $tester->execute(['--since' => 'abc']));
        self::assertStringContainsString('Invalid --since', $tester->getDisplay());
    }
}

<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Tests\Core\Storage;

use Aleblanc\LogUi\Core\Storage\MergedLogReader;
use Aleblanc\LogUi\Core\Storage\PlainLogReader;
use Aleblanc\LogUi\Core\Time\TimestampParser;
use PHPUnit\Framework\TestCase;

final class MergedLogReaderTest extends TestCase
{
    private const BASE = '2026-10-13 00:00:00';

    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        $this->files = [];
    }

    /**
     * Writes a log file whose records sit at the given offsets (in seconds) from BASE.
     *
     * @param list<int> $seconds
     */
    private function makeFile(string $name, array $seconds): string
    {
        $path = tempnam(sys_get_temp_dir(), 'logui_merge_').'_'.$name.'.log';
        $this->files[] = $path;

        $base = strtotime(self::BASE.' UTC');
        $lines = [];
        foreach ($seconds as $second) {
            $lines[] = sprintf('[%s] app.INFO: %s@%d', gmdate('Y-m-d\TH:i:sP', $base + $second), $name, $second);
        }
        file_put_contents($path, implode("\n", $lines)."\n");

        return $path;
    }

    private function merged(): MergedLogReader
    {
        return new MergedLogReader(new PlainLogReader(new TimestampParser('UTC')));
    }

    private function at(int $second): int
    {
        return (strtotime(self::BASE.' UTC') + $second) * 1000;
    }

    /**
     * @param array<string,string> $sources
     *
     * @return list<array<string,mixed>>
     */
    private function tail(array $sources): array
    {
        return iterator_to_array($this->merged()->readTail($sources), false);
    }

    public function test_rows_are_interleaved_in_chronological_order(): void
    {
        $sources = [
            'a.log' => $this->makeFile('a', [0, 20, 40]),
            'b.log' => $this->makeFile('b', [10, 30, 50]),
        ];

        $rows = $this->tail($sources);

        $timestamps = array_map(static fn (array $r): ?int => $r['ts'], $rows);
        self::assertSame([
            $this->at(0), $this->at(10), $this->at(20),
            $this->at(30), $this->at(40), $this->at(50),
        ], $timestamps);
    }

    public function test_each_row_is_tagged_with_its_source(): void
    {
        $sources = [
            'a.log' => $this->makeFile('a', [0, 20]),
            'b.log' => $this->makeFile('b', [10]),
        ];

        $labels = array_map(static fn (array $r): string => $r['file'], $this->tail($sources));

        self::assertSame(['a.log', 'b.log', 'a.log'], $labels);
    }

    public function test_merging_a_single_source_behaves_like_reading_it(): void
    {
        $sources = ['solo.log' => $this->makeFile('solo', [5, 6, 7])];

        $rows = $this->tail($sources);

        self::assertCount(3, $rows);
        self::assertSame('solo.log', $rows[0]['file']);
        self::assertSame($this->at(5), $rows[0]['ts']);
    }

    public function test_no_sources_yields_nothing(): void
    {
        self::assertSame([], $this->tail([]));
    }

    public function test_empty_file_among_others_is_skipped(): void
    {
        $empty = tempnam(sys_get_temp_dir(), 'logui_merge_empty_').'.log';
        $this->files[] = $empty;
        file_put_contents($empty, '');

        $sources = [
            'empty.log' => $empty,
            'a.log' => $this->makeFile('a', [1, 2]),
        ];

        $rows = $this->tail($sources);

        self::assertCount(2, $rows);
        self::assertSame(['a.log', 'a.log'], array_column($rows, 'file'));
    }

    public function test_identical_timestamps_do_not_starve_a_source(): void
    {
        $sources = [
            'a.log' => $this->makeFile('a', [5, 5]),
            'b.log' => $this->makeFile('b', [5, 5]),
        ];

        $rows = $this->tail($sources);

        self::assertCount(4, $rows);
        $labels = array_column($rows, 'file');
        self::assertContains('a.log', $labels);
        self::assertContains('b.log', $labels);
    }

    public function test_undatable_lines_stay_attached_to_their_own_source(): void
    {
        $a = tempnam(sys_get_temp_dir(), 'logui_merge_a_').'.log';
        $this->files[] = $a;
        $base = strtotime(self::BASE.' UTC');
        file_put_contents($a, sprintf(
            "[%s] app.ERROR: boom\n#0 /app/src/Foo.php(12)\n[%s] app.INFO: after\n",
            gmdate('Y-m-d\TH:i:sP', $base + 10),
            gmdate('Y-m-d\TH:i:sP', $base + 30),
        ));

        $sources = ['a.log' => $a, 'b.log' => $this->makeFile('b', [20])];

        $messages = array_map(static fn (array $r): string => $r['message'], $this->tail($sources));

        // The stack frame is merged into the ERROR record, which must still come before b@20.
        self::assertStringContainsString('boom', $messages[0]);
        self::assertStringContainsString('#0 /app/src/Foo.php', $messages[0]);
        self::assertStringContainsString('b@20', $messages[1]);
        self::assertStringContainsString('after', $messages[2]);
    }

    public function test_range_is_applied_across_every_source(): void
    {
        $sources = [
            'a.log' => $this->makeFile('a', [0, 50, 100, 150]),
            'b.log' => $this->makeFile('b', [25, 75, 125]),
        ];

        $rows = iterator_to_array(
            $this->merged()->readRange($sources, $this->at(40), $this->at(110)),
            false,
        );

        $timestamps = array_map(static fn (array $r): ?int => $r['ts'], $rows);
        self::assertSame([$this->at(50), $this->at(75), $this->at(100)], $timestamps);
    }
}

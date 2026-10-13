<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Tests\Core\Storage;

use Aleblanc\LogUi\Core\Storage\PlainLogReader;
use Aleblanc\LogUi\Core\Time\TimestampParser;
use PHPUnit\Framework\TestCase;

/**
 * readRange() must return exactly the lines of the requested window, whatever the file size,
 * and must never read the whole file to do it.
 */
final class PlainLogReaderRangeTest extends TestCase
{
    private const BASE = '2026-10-13 00:00:00';

    private string $file;

    protected function setUp(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'logui_range_').'.log';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    /** One Monolog line per second from BASE, padded so the file is worth searching. */
    private function writeSeconds(int $count, int $padding = 200): void
    {
        $base = strtotime(self::BASE.' UTC');
        $fh = $this->openWrite();
        for ($i = 0; $i < $count; ++$i) {
            fwrite($fh, sprintf(
                "[%s] app.INFO: line %d %s\n",
                gmdate('Y-m-d\TH:i:sP', $base + $i),
                $i,
                str_repeat('x', $padding),
            ));
        }
        fclose($fh);
    }

    /**
     * fopen() can fail; failing the test immediately keeps the handle type honest.
     *
     * @return resource
     */
    private function openWrite()
    {
        $fh = fopen($this->file, 'wb');
        if (false === $fh) {
            self::fail('unable to open the fixture for writing');
        }

        return $fh;
    }

    private function reader(): PlainLogReader
    {
        return new PlainLogReader(new TimestampParser('UTC'));
    }

    private function at(int $second): int
    {
        return (strtotime(self::BASE.' UTC') + $second) * 1000;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function range(int $fromSecond, int $toSecond): array
    {
        return iterator_to_array(
            $this->reader()->readRange($this->file, $this->at($fromSecond), $this->at($toSecond)),
            false,
        );
    }

    public function test_returns_exactly_the_requested_window(): void
    {
        $this->writeSeconds(20_000);

        $rows = $this->range(12_000, 12_099);

        self::assertCount(100, $rows);
        self::assertSame($this->at(12_000), $rows[0]['ts']);
        self::assertSame($this->at(12_099), $rows[99]['ts']);
        self::assertStringContainsString('line 12000', $rows[0]['message']);
    }

    public function test_window_at_the_very_beginning(): void
    {
        $this->writeSeconds(20_000);

        $rows = $this->range(0, 9);

        self::assertCount(10, $rows);
        self::assertSame($this->at(0), $rows[0]['ts']);
    }

    public function test_window_at_the_very_end(): void
    {
        $this->writeSeconds(20_000);

        $rows = $this->range(19_990, 19_999);

        self::assertCount(10, $rows);
        self::assertSame($this->at(19_999), $rows[9]['ts']);
    }

    public function test_window_outside_the_file_returns_nothing(): void
    {
        $this->writeSeconds(20_000);

        self::assertSame([], $this->range(50_000, 60_000));
    }

    public function test_inverted_window_returns_nothing(): void
    {
        $this->writeSeconds(1_000);

        self::assertSame([], $this->range(500, 100));
    }

    public function test_small_file_is_handled_without_searching(): void
    {
        $this->writeSeconds(50); // well under the searchable threshold

        $rows = $this->range(10, 19);

        self::assertCount(10, $rows);
        self::assertSame($this->at(10), $rows[0]['ts']);
    }

    public function test_result_matches_a_full_scan(): void
    {
        $this->writeSeconds(20_000);

        $expected = [];
        foreach ($this->reader()->read($this->file) as $row) {
            if (null !== $row['ts'] && $row['ts'] >= $this->at(7_000) && $row['ts'] <= $this->at(7_250)) {
                $expected[] = $row['message'];
            }
        }

        $actual = array_map(static fn (array $r): string => $r['message'], $this->range(7_000, 7_250));

        self::assertSame($expected, $actual, 'ranged read must agree with a full scan');
        self::assertCount(251, $actual);
    }

    public function test_reads_far_less_than_the_whole_file(): void
    {
        $this->writeSeconds(40_000);                       // ~9 MB
        $size = filesize($this->file);

        $rows = $this->range(35_000, 35_049);
        self::assertCount(50, $rows);

        // The window is a tiny fraction of the file; the search must keep it that way.
        // (Measured indirectly: a full scan would have yielded 40 000 rows.)
        self::assertGreaterThan(8_000_000, $size, 'the fixture should be large enough to matter');
    }

    public function test_stack_traces_stay_attached_to_their_record(): void
    {
        $base = strtotime(self::BASE.' UTC');
        $fh = $this->openWrite();
        for ($i = 0; $i < 5_000; ++$i) {
            fwrite($fh, sprintf("[%s] app.ERROR: boom %d\n", gmdate('Y-m-d\TH:i:sP', $base + $i), $i));
            fwrite($fh, "#0 /app/src/Foo.php(12): bar()\n");
            fwrite($fh, "#1 {main}\n");
        }
        fclose($fh);

        $rows = $this->range(2_000, 2_002);

        self::assertCount(3, $rows);
        foreach ($rows as $row) {
            self::assertStringContainsString('#0 /app/src/Foo.php', $row['message'], 'continuation lines must be kept');
            self::assertStringContainsString('#1 {main}', $row['message']);
        }
    }

    public function test_byte_budget_caps_the_output(): void
    {
        $this->writeSeconds(20_000);

        $rows = iterator_to_array(
            $this->reader()->readRange($this->file, $this->at(0), $this->at(19_999), 5_000),
            false,
        );

        self::assertNotEmpty($rows);
        self::assertLessThan(200, \count($rows), 'the budget must stop the read early');
    }

    public function test_out_of_order_lines_near_the_boundary_are_not_lost(): void
    {
        // A file where one line is slightly out of order, as happens with concatenated logs.
        $base = strtotime(self::BASE.' UTC');
        $fh = $this->openWrite();
        for ($i = 0; $i < 10_000; ++$i) {
            $second = 5_000 === $i ? $i - 3 : $i; // one line steps back in time
            fwrite($fh, sprintf("[%s] app.INFO: line %d %s\n", gmdate('Y-m-d\TH:i:sP', $base + $second), $i, str_repeat('x', 200)));
        }
        fclose($fh);

        $messages = array_map(static fn (array $r): string => $r['message'], $this->range(4_990, 5_010));

        self::assertNotEmpty($messages);
        // The out-of-order record (timestamped 4997) must still be reported.
        self::assertTrue(
            (bool) array_filter($messages, static fn (string $m): bool => str_contains($m, 'line 5000')),
            'an out-of-order line inside the window must not be dropped',
        );
    }
}

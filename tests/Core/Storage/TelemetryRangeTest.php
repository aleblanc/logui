<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Tests\Core\Storage;

use Aleblanc\LogUi\Core\Storage\PlainLogReader;
use Aleblanc\LogUi\Core\Storage\TelemetryReader;
use Aleblanc\LogUi\Core\Time\TimestampParser;
use PHPUnit\Framework\TestCase;

/**
 * LogUI's own telemetry lines must be **datable**, even though the explorer never displays them.
 *
 * In production the telemetry file is the application's own log, and it can be almost entirely
 * telemetry. Undated lines are invisible to the binary search that locates a time range, and they
 * never satisfy the "past the end of the window" test that stops the scan — so both degrade to
 * reading the file whole. On a 1 GB prod.log that was a gigabyte read per request, twice per page
 * load, which is what made the explorer unusable in production.
 */
final class TelemetryRangeTest extends TestCase
{
    private const BASE = '2026-10-13 00:00:00';

    private string $file;

    protected function setUp(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'logui_telemetry_').'.log';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    /** One telemetry line per second, fat like the real thing (a whole profile per line). */
    private function writeTelemetry(int $count): void
    {
        $base = strtotime(self::BASE.' UTC');
        $handle = fopen($this->file, 'wb');
        if (false === $handle) {
            self::fail('unable to open the fixture for writing');
        }

        for ($i = 0; $i < $count; ++$i) {
            fwrite($handle, TelemetryReader::SENTINEL.json_encode([
                'id' => sprintf('%016x', $i),
                'type' => 'cli',
                'at' => gmdate('Y-m-d\TH:i:sP', $base + $i),
                'label' => 'app:import',
                'status' => 0,
                'duration_ms' => 12.5,
                'records' => array_fill(0, 10, ['level' => 'info', 'message' => str_repeat('x', 40)]),
            ])."\n");
        }
        fclose($handle);
    }

    private function reader(): PlainLogReader
    {
        return new PlainLogReader(new TimestampParser('UTC'));
    }

    private function at(int $second): int
    {
        return (strtotime(self::BASE.' UTC') + $second) * 1000;
    }

    public function test_a_telemetry_line_carries_the_date_from_its_payload(): void
    {
        $this->writeTelemetry(1);

        $rows = iterator_to_array($this->reader()->readTail($this->file), false);

        self::assertCount(1, $rows);
        self::assertSame($this->at(0), $rows[0]['ts'], 'the `at` field is the line\'s timestamp');
        self::assertFalse($rows[0]['ts_inferred'], 'it is a real date, not one guessed from the file');
    }

    public function test_a_telemetry_line_is_a_request_or_command_row_not_a_raw_line(): void
    {
        // A request is a record like any other: the explorer lists it under its label, and the
        // whole payload stays in `context` so the search box and `| json` can still see inside.
        $this->writeTelemetry(1);

        $rows = iterator_to_array($this->reader()->readTail($this->file), false);

        self::assertSame('command', $rows[0]['type']);
        self::assertSame('app:import', $rows[0]['message'], 'the label, not the raw JSON');
        self::assertStringContainsString('"label":"app:import"', (string) $rows[0]['context']);
    }

    public function test_a_range_inside_a_telemetry_only_file_returns_only_that_range(): void
    {
        // 4000 fat lines ≈ several MB: enough that reading the whole file instead of seeking
        // would be the difference the bug was made of.
        $this->writeTelemetry(4000);

        $rows = iterator_to_array($this->reader()->readRange($this->file, $this->at(3000), $this->at(3009)), false);

        self::assertCount(10, $rows, 'exactly the ten seconds asked for');
        self::assertSame($this->at(3000), $rows[0]['ts']);
        self::assertSame($this->at(3009), $rows[9]['ts']);
    }

    /**
     * The scan has to *stop* once it is past the window. Without dates it ran to EOF, so a window
     * at the start of the file still read all of it.
     */
    public function test_a_range_at_the_start_does_not_read_to_the_end(): void
    {
        $this->writeTelemetry(4000);

        $before = memory_get_peak_usage();
        $rows = iterator_to_array($this->reader()->readRange($this->file, $this->at(0), $this->at(4)), false);

        self::assertCount(5, $rows);
        self::assertSame($this->at(4), $rows[4]['ts'], 'it stopped at the end of the window');
        self::assertLessThan(8_000_000, memory_get_peak_usage() - $before, 'the file was not slurped');
    }

    public function test_a_telemetry_line_without_a_date_is_still_read_as_a_raw_line(): void
    {
        // Older or truncated telemetry has no `at`. It must not disappear, it just has no date.
        file_put_contents($this->file, TelemetryReader::SENTINEL.'{"id":"abc","type":"cli"}'."\n");

        $rows = iterator_to_array($this->reader()->readTail($this->file), false);

        self::assertCount(1, $rows);
        self::assertNull($rows[0]['ts']);
        self::assertStringContainsString('"id":"abc"', $rows[0]['message']);
    }
}

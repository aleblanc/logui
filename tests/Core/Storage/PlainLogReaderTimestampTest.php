<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Tests\Core\Storage;

use Aleblanc\LogUi\Core\Storage\PlainLogReader;
use Aleblanc\LogUi\Core\Time\TimestampParser;
use PHPUnit\Framework\TestCase;

/**
 * The reader must expose an epoch timestamp for every datable line, computed on the fly —
 * the log file itself is never modified.
 */
final class PlainLogReaderTimestampTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'logui_ts_').'.log';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    private function write(string $contents): void
    {
        file_put_contents($this->file, $contents);
    }

    private function reader(string $tz = 'UTC'): PlainLogReader
    {
        return new PlainLogReader(new TimestampParser($tz));
    }

    /** @return list<array<string,mixed>> */
    private function rows(PlainLogReader $reader): array
    {
        return iterator_to_array($reader->read($this->file), false);
    }

    public function test_stamps_every_known_format(): void
    {
        $this->write(<<<LOG
            [2026-10-13T12:34:56+00:00] app.INFO: monolog iso
            [2026-10-13 12:34:57] app.INFO: monolog legacy
            2026/10/13 12:34:58 [error] nginx error line
            127.0.0.1 - - [13/Oct/2026:12:34:59 +0000] "GET / HTTP/1.1" 200 12
            LOG);

        $rows = $this->rows($this->reader());
        self::assertCount(4, $rows);

        $expected = strtotime('2026-10-13T12:34:56Z') * 1000;
        foreach ($rows as $i => $row) {
            self::assertNotNull($row['ts'], 'row '.$i.' should be datable');
            self::assertSame($expected + $i * 1000, $row['ts'], 'row '.$i);
            self::assertFalse($row['ts_inferred'], 'row '.$i.' is read, not inferred');
        }
    }

    public function test_sub_second_precision_is_kept(): void
    {
        $this->write('[2026-10-13T12:34:56.250000+00:00] app.INFO: with micros');

        $rows = $this->rows($this->reader());
        self::assertSame(strtotime('2026-10-13T12:34:56Z') * 1000 + 250, $rows[0]['ts']);
    }

    public function test_undatable_line_has_null_timestamp(): void
    {
        $this->write('this line has no date at all');

        $rows = $this->rows($this->reader());
        self::assertNull($rows[0]['ts']);
        self::assertFalse($rows[0]['ts_inferred']);
    }

    public function test_time_only_line_is_inferred_from_file_mtime(): void
    {
        // Symfony console format: a time of day, no date.
        $this->write('12:34:56 INFO      [app] console line');
        $mtime = strtotime('2026-10-13 18:00:00 UTC');
        touch($this->file, $mtime);

        $rows = $this->rows($this->reader());

        self::assertSame(strtotime('2026-10-13 12:34:56 UTC') * 1000, $rows[0]['ts']);
        self::assertTrue($rows[0]['ts_inferred'], 'placement is a best-effort guess');
    }

    public function test_time_only_line_after_mtime_falls_back_to_previous_day(): void
    {
        // The file was last written at 01:00; a 23:30 line must belong to the day before.
        $this->write('23:30:00 INFO      [app] just before midnight');
        touch($this->file, strtotime('2026-10-13 01:00:00 UTC'));

        $rows = $this->rows($this->reader());

        self::assertSame(strtotime('2026-10-12 23:30:00 UTC') * 1000, $rows[0]['ts']);
        self::assertTrue($rows[0]['ts_inferred']);
    }

    public function test_timestamps_are_monotonic_on_a_realistic_file(): void
    {
        $lines = [];
        $base = strtotime('2026-10-13 00:00:00 UTC');
        for ($i = 0; $i < 200; ++$i) {
            $lines[] = sprintf('[%s] app.INFO: line %d', gmdate('Y-m-d\TH:i:sP', $base + $i * 37), $i);
        }
        $this->write(implode("\n", $lines));

        $previous = null;
        foreach ($this->rows($this->reader()) as $row) {
            self::assertNotNull($row['ts']);
            if (null !== $previous) {
                self::assertGreaterThan($previous, $row['ts'], 'timestamps must ascend');
            }
            $previous = $row['ts'];
        }
    }

    public function test_reading_does_not_modify_the_file(): void
    {
        $contents = "[2026-10-13T12:34:56+00:00] app.INFO: untouched\n";
        $this->write($contents);
        $sizeBefore = filesize($this->file);
        $hashBefore = md5_file($this->file);

        $this->rows($this->reader());
        clearstatcache();

        self::assertSame($sizeBefore, filesize($this->file), 'file size must not change');
        self::assertSame($hashBefore, md5_file($this->file), 'file content must not change');
    }
}

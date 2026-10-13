<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Tests\Core\Storage;

use Aleblanc\LogUi\Core\Storage\LogSeeker;
use PHPUnit\Framework\TestCase;

/**
 * The seeker is tested on its own, with a trivial timestamp extractor, so its search logic is
 * verified independently of any log format.
 */
final class LogSeekerTest extends TestCase
{
    private string $file;

    /** @var resource|null */
    private $handle;

    protected function setUp(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'logui_seek_').'.log';
    }

    protected function tearDown(): void
    {
        if (\is_resource($this->handle)) {
            fclose($this->handle);
        }
        @unlink($this->file);
    }

    /**
     * fopen() can fail; failing the test immediately keeps the handle type honest
     * (no suppression, no cast).
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

    /** @return resource */
    private function openRead()
    {
        $fh = fopen($this->file, 'rb');
        if (false === $fh) {
            self::fail('unable to open the fixture for reading');
        }

        return $fh;
    }

    /**
     * Lines look like "0000123 <padding>" where the number is both the timestamp and the order.
     * Padding makes the file large enough for the binary search to engage.
     */
    private function writeOrdered(int $lines, int $padding = 200): void
    {
        $fh = $this->openWrite();
        for ($i = 0; $i < $lines; ++$i) {
            fwrite($fh, sprintf("%07d %s\n", $i, str_repeat('x', $padding)));
        }
        fclose($fh);
    }

    private function seeker(): LogSeeker
    {
        return new LogSeeker(static function (string $line): ?int {
            // "undatable" lines start with a dash.
            if ('' === $line || '-' === $line[0]) {
                return null;
            }

            return (int) substr($line, 0, 7);
        });
    }

    /** @return array{resource,int} */
    private function open(): array
    {
        $this->handle = $this->openRead();

        return [$this->handle, (int) filesize($this->file)];
    }

    /** Reads the first datable timestamp at or after an offset. */
    private function firstTimestampAt(int $offset): ?int
    {
        [$handle] = $this->open();
        fseek($handle, $offset);
        if ($offset > 0) {
            fgets($handle);
        }
        while (false !== ($line = fgets($handle))) {
            $line = rtrim($line, "\n");
            if ('' !== $line && '-' !== $line[0]) {
                return (int) substr($line, 0, 7);
            }
        }

        return null;
    }

    public function test_small_file_is_read_from_the_start(): void
    {
        $this->writeOrdered(10);
        [$handle, $size] = $this->open();

        self::assertLessThan(LogSeeker::MIN_SEARCHABLE_BYTES, $size);
        self::assertSame(0, $this->seeker()->offsetFor($handle, $size, 5));
    }

    public function test_offset_is_before_the_target_but_close_to_it(): void
    {
        $this->writeOrdered(20_000);           // ~4 MB
        [$handle, $size] = $this->open();

        $target = 15_000;
        $offset = $this->seeker()->offsetFor($handle, $size, $target);

        // Never past the target, otherwise matching lines would be skipped.
        $found = $this->firstTimestampAt($offset);
        self::assertNotNull($found);
        self::assertLessThanOrEqual($target, $found, 'the offset must not overshoot the target');

        // And close enough that the linear scan left to do is small.
        self::assertGreaterThan($target - 2_000, $found, 'the search should land near the target');
    }

    public function test_target_before_the_file_returns_the_start(): void
    {
        $this->writeOrdered(20_000);
        [$handle, $size] = $this->open();

        self::assertSame(0, $this->seeker()->offsetFor($handle, $size, -1));
    }

    public function test_target_after_the_file_returns_near_the_end(): void
    {
        $this->writeOrdered(20_000);
        [$handle, $size] = $this->open();

        $offset = $this->seeker()->offsetFor($handle, $size, 999_999);

        self::assertGreaterThan(0, $offset);
        self::assertLessThanOrEqual($size, $offset);
    }

    public function test_file_without_any_datable_line_stays_at_the_start(): void
    {
        $fh = $this->openWrite();
        for ($i = 0; $i < 20_000; ++$i) {
            fwrite($fh, '- undatable line '.str_repeat('x', 200)."\n");
        }
        fclose($fh);
        [$handle, $size] = $this->open();

        // Nothing can be proven about the upper half, so the search collapses to the start
        // and the caller simply scans — no lines are lost.
        self::assertSame(0, $this->seeker()->offsetFor($handle, $size, 1_000));
    }

    public function test_sparse_datable_lines_are_still_found(): void
    {
        // One datable line every 50 lines: the probe must scan forward to orient itself.
        $fh = $this->openWrite();
        for ($i = 0; $i < 20_000; ++$i) {
            fwrite($fh, 0 === $i % 50
                ? sprintf("%07d %s\n", $i, str_repeat('x', 200))
                : '- continuation '.str_repeat('x', 200)."\n");
        }
        fclose($fh);
        [$handle, $size] = $this->open();

        $offset = $this->seeker()->offsetFor($handle, $size, 10_000);
        $found = $this->firstTimestampAt($offset);

        self::assertNotNull($found);
        self::assertLessThanOrEqual(10_000, $found);
    }

    public function test_search_cost_is_logarithmic(): void
    {
        $this->writeOrdered(60_000);           // ~12 MB
        [$handle, $size] = $this->open();

        $reads = 0;
        $seeker = new LogSeeker(static function (string $line) use (&$reads): ?int {
            ++$reads;

            return '' === $line ? null : (int) substr($line, 0, 7);
        });
        $seeker->offsetFor($handle, $size, 30_000);

        // A full scan would read 60 000 lines; a binary search reads a few per probe.
        self::assertLessThan(500, $reads, 'the seeker must not degrade into a linear scan');
    }
}

<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Tests\Core\Storage;

use Aleblanc\LogUi\Core\Storage\LogTruncator;
use PHPUnit\Framework\TestCase;

final class LogTruncatorTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir().'/logui-trunc-'.uniqid().'.log';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    public function test_keeps_only_the_last_bytes_starting_on_a_line_boundary(): void
    {
        // 10 lines of 10 bytes each ("line NN\n" padded) → 100 bytes.
        $lines = [];
        for ($i = 0; $i < 10; ++$i) {
            $lines[] = sprintf('line %04d', $i); // 9 chars + "\n" = 10 bytes
        }
        file_put_contents($this->file, implode("\n", $lines)."\n");

        // Keep ~25 bytes → the tail lands mid-line; the partial first line is dropped, so the
        // result starts on a clean record boundary.
        LogTruncator::keepTail($this->file, 25);

        $out = (string) file_get_contents($this->file);
        self::assertLessThanOrEqual(25, \strlen($out));
        self::assertStringEndsWith("line 0009\n", $out);
        self::assertStringStartsWith('line ', $out, 'the kept data begins on a whole line');
        self::assertStringNotContainsString('0007', $out, 'the partial first line was dropped');
    }

    public function test_empties_the_file_when_keeping_zero(): void
    {
        file_put_contents($this->file, "a\nb\nc\n");

        LogTruncator::keepTail($this->file, 0);

        self::assertSame('', (string) file_get_contents($this->file));
    }

    public function test_is_a_no_op_when_the_file_is_already_small_enough(): void
    {
        file_put_contents($this->file, "a\nb\n");

        LogTruncator::keepTail($this->file, 1_000_000);

        self::assertSame("a\nb\n", (string) file_get_contents($this->file));
    }

    public function test_missing_file_is_safe(): void
    {
        self::assertSame(0, LogTruncator::keepTail('/no/such/file.log', 100));
    }
}

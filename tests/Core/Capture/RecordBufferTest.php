<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Tests\Core\Capture;

use Aleblanc\LogUi\Core\Capture\RecordBuffer;
use Aleblanc\LogUi\Core\Model\LogRecord;
use PHPUnit\Framework\TestCase;

final class RecordBufferTest extends TestCase
{
    public function test_stores_records_and_counts_levels(): void
    {
        $buffer = new RecordBuffer(max: 10);
        $buffer->add(new LogRecord('error', 'app', 'a', [], 0.0));
        $buffer->add(new LogRecord('error', 'app', 'b', [], 1.0));
        $buffer->add(new LogRecord('warning', 'app', 'c', [], 2.0));

        self::assertCount(3, $buffer->all());
        self::assertSame(['error' => 2, 'warning' => 1], $buffer->levelCounts());
        self::assertFalse($buffer->truncated());
    }

    public function test_caps_at_max_and_flags_truncated(): void
    {
        $buffer = new RecordBuffer(max: 2);
        $buffer->add(new LogRecord('info', 'app', '1', [], 0.0));
        $buffer->add(new LogRecord('info', 'app', '2', [], 0.0));
        $buffer->add(new LogRecord('info', 'app', '3', [], 0.0));

        self::assertCount(2, $buffer->all());
        self::assertTrue($buffer->truncated());
    }
}

<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Tests\Core\Model;

use Aleblanc\LogUi\Core\Model\LogRecord;
use PHPUnit\Framework\TestCase;

final class LogRecordTest extends TestCase
{
    public function test_round_trips_through_array(): void
    {
        $record = new LogRecord('warning', 'app', 'Disk almost full', ['free' => '2%'], 12.5);

        $restored = LogRecord::fromArray($record->toArray());

        self::assertSame('warning', $restored->level);
        self::assertSame('app', $restored->channel);
        self::assertSame('Disk almost full', $restored->message);
        self::assertSame(['free' => '2%'], $restored->context);
        self::assertSame(12.5, $restored->t);
    }

    public function test_from_array_tolerates_missing_context(): void
    {
        $restored = LogRecord::fromArray(['level' => 'info', 'channel' => 'app', 'msg' => 'hi', 't' => 0.0]);

        self::assertSame([], $restored->context);
    }
}

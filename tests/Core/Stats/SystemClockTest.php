<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Tests\Core\Stats;

use Aleblanc\LogUi\Core\Stats\Clock;
use Aleblanc\LogUi\Core\Stats\SystemClock;
use PHPUnit\Framework\TestCase;

final class SystemClockTest extends TestCase
{
    public function test_is_a_clock(): void
    {
        self::assertInstanceOf(Clock::class, new SystemClock());
    }

    public function test_microtime_is_positive_float(): void
    {
        self::assertGreaterThan(0.0, (new SystemClock())->microtime());
    }

    public function test_now_returns_immutable_date(): void
    {
        self::assertInstanceOf(\DateTimeImmutable::class, (new SystemClock())->now());
    }
}

<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Tests\Core\Model;

use Aleblanc\LogUi\Core\Model\LogLevel;
use PHPUnit\Framework\TestCase;

final class LogLevelTest extends TestCase
{
    public function test_severity_orders_levels(): void
    {
        self::assertLessThan(LogLevel::severity('error'), LogLevel::severity('warning'));
        self::assertGreaterThan(LogLevel::severity('warning'), LogLevel::severity('critical'));
    }

    public function test_severity_is_case_insensitive(): void
    {
        self::assertSame(LogLevel::severity('ERROR'), LogLevel::severity('error'));
    }

    public function test_unknown_level_is_lowest(): void
    {
        self::assertSame(0, LogLevel::severity('nonsense'));
    }

    public function test_all_returns_known_levels_low_to_high(): void
    {
        self::assertSame('debug', LogLevel::all()[0]);
        self::assertSame('emergency', LogLevel::all()[count(LogLevel::all()) - 1]);
    }
}

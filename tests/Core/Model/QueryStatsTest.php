<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Tests\Core\Model;

use Aleblanc\LogUi\Core\Model\ProfileType;
use Aleblanc\LogUi\Core\Model\QueryStats;
use PHPUnit\Framework\TestCase;

final class QueryStatsTest extends TestCase
{
    public function test_profile_type_has_http_and_cli(): void
    {
        self::assertSame('http', ProfileType::Http->value);
        self::assertSame('cli', ProfileType::Cli->value);
    }

    public function test_empty_has_zero_count(): void
    {
        self::assertSame(0, QueryStats::empty()->count);
        self::assertSame([], QueryStats::empty()->slow);
    }

    public function test_round_trips_through_array(): void
    {
        $stats = new QueryStats(12, [['sql' => 'SELECT 1', 'ms' => 91.2]]);

        $restored = QueryStats::fromArray($stats->toArray());

        self::assertSame(12, $restored->count);
        self::assertSame([['sql' => 'SELECT 1', 'ms' => 91.2]], $restored->slow);
    }
}

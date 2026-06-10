<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Tests\Core\Stats;

use Aleblanc\LogUi\Core\Stats\QueryCounter;
use PHPUnit\Framework\TestCase;

final class QueryCounterTest extends TestCase
{
    public function test_counts_every_query(): void
    {
        $counter = new QueryCounter(slowMs: 50.0);
        $counter->record('SELECT 1', 10.0);
        $counter->record('SELECT 2', 20.0);

        self::assertSame(2, $counter->stats()->count);
    }

    public function test_keeps_only_slow_queries(): void
    {
        $counter = new QueryCounter(slowMs: 50.0);
        $counter->record('fast', 10.0);
        $counter->record('slow', 91.234);

        $stats = $counter->stats();

        self::assertSame(2, $stats->count);
        self::assertCount(1, $stats->slow);
        self::assertSame('slow', $stats->slow[0]['sql']);
        self::assertSame(91.2, $stats->slow[0]['ms']);
    }
}

<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Core\Stats;

use Aleblanc\LogUi\Core\Model\QueryStats;

final class QueryCounter
{
    private int $count = 0;

    /** @var list<array{sql:string,ms:float}> */
    private array $slow = [];

    public function __construct(private readonly float $slowMs)
    {
    }

    public function record(string $sql, float $ms): void
    {
        ++$this->count;
        if ($ms >= $this->slowMs) {
            $this->slow[] = ['sql' => $sql, 'ms' => round($ms, 1)];
        }
    }

    public function stats(): QueryStats
    {
        return new QueryStats($this->count, $this->slow);
    }
}

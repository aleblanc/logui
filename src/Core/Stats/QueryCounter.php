<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Core\Stats;

use Aleblanc\LogUi\Core\Model\QueryStats;

final class QueryCounter
{
    private int $count = 0;

    /** @var list<array{sql:string,ms:float,t?:float}> */
    private array $slow = [];

    public function __construct(private readonly float $slowMs)
    {
    }

    /**
     * @param float|null $t milliseconds from the start of the request to when this query ran —
     *                      recorded only when known, so the UI can place it on the timeline
     */
    public function record(string $sql, float $ms, ?float $t = null): void
    {
        ++$this->count;
        if ($ms >= $this->slowMs) {
            $entry = ['sql' => $sql, 'ms' => round($ms, 1)];
            if (null !== $t) {
                $entry['t'] = round(max(0, $t), 1);
            }
            $this->slow[] = $entry;
        }
    }

    public function stats(): QueryStats
    {
        return new QueryStats($this->count, $this->slow);
    }
}

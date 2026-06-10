<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Tests\Support;

use Aleblanc\LogUi\Core\Stats\Clock;

final class FixedClock implements Clock
{
    private float $micro;

    public function __construct(
        private \DateTimeImmutable $now,
        float $micro = 1000.0,
    ) {
        $this->micro = $micro;
    }

    public function microtime(): float
    {
        return $this->micro;
    }

    /** Advance the monotonic clock by N milliseconds (for duration assertions). */
    public function advanceMs(float $ms): void
    {
        $this->micro += $ms / 1000.0;
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }
}

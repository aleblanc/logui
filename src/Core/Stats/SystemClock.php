<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Core\Stats;

final class SystemClock implements Clock
{
    public function microtime(): float
    {
        return microtime(true);
    }

    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }
}

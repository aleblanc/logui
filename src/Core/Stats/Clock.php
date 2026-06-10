<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Core\Stats;

interface Clock
{
    /** High-resolution monotonic-ish seconds, used for durations. */
    public function microtime(): float;

    /** Wall-clock time, used for timestamps and daily file names. */
    public function now(): \DateTimeImmutable;
}

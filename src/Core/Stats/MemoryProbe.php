<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Core\Stats;

final class MemoryProbe
{
    public function peakMb(): float
    {
        return round(memory_get_peak_usage(true) / 1048576, 1);
    }

    public function currentMb(): float
    {
        return round(memory_get_usage(true) / 1048576, 1);
    }
}

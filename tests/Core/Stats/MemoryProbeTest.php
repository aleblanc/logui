<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Tests\Core\Stats;

use Aleblanc\LogUi\Core\Stats\MemoryProbe;
use PHPUnit\Framework\TestCase;

final class MemoryProbeTest extends TestCase
{
    public function test_peak_mb_is_positive(): void
    {
        self::assertGreaterThan(0.0, (new MemoryProbe())->peakMb());
    }
}

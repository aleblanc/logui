<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Core\Capture;

use Aleblanc\LogUi\Core\Model\ProfileType;
use Aleblanc\LogUi\Core\Stats\Clock;
use Aleblanc\LogUi\Core\Stats\MemoryProbe;
use Aleblanc\LogUi\Core\Stats\QueryCounter;

final class ProfileContextFactory
{
    public function __construct(
        private readonly Clock $clock,
        private readonly Redactor $redactor,
        private readonly MemoryProbe $memory,
        private readonly int $maxRecords,
        private readonly float $slowMs,
    ) {
    }

    public function create(string $id, ProfileType $type, string $label): ProfileContext
    {
        return new ProfileContext(
            $id,
            $type,
            $label,
            $this->clock,
            new RecordBuffer($this->maxRecords),
            new QueryCounter($this->slowMs),
            $this->redactor,
            $this->memory,
        );
    }
}

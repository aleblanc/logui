<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Core\Capture;

use Aleblanc\LogUi\Core\Model\LogRecord;

final class RecordBuffer
{
    /** @var list<LogRecord> */
    private array $records = [];

    private bool $truncated = false;

    public function __construct(private readonly int $max)
    {
    }

    public function add(LogRecord $record): void
    {
        if (\count($this->records) >= $this->max) {
            $this->truncated = true;

            return;
        }
        $this->records[] = $record;
    }

    /** @return list<LogRecord> */
    public function all(): array
    {
        return $this->records;
    }

    public function truncated(): bool
    {
        return $this->truncated;
    }

    /** @return array<string,int> level => count */
    public function levelCounts(): array
    {
        $counts = [];
        foreach ($this->records as $record) {
            $counts[$record->level] = ($counts[$record->level] ?? 0) + 1;
        }

        return $counts;
    }
}

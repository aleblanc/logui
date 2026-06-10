<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Core\Capture;

use Aleblanc\LogUi\Core\Model\LogRecord;
use Aleblanc\LogUi\Core\Model\Profile;
use Aleblanc\LogUi\Core\Model\ProfileType;
use Aleblanc\LogUi\Core\Stats\Clock;
use Aleblanc\LogUi\Core\Stats\MemoryProbe;
use Aleblanc\LogUi\Core\Stats\QueryCounter;

final class ProfileContext
{
    private string $label;
    private ?string $method = null;
    private readonly float $startMicro;
    private readonly float $startMemMb;
    private readonly \DateTimeImmutable $startedAt;

    /** @var array{class:string,message:string,trace:string}|null */
    private ?array $exception = null;

    public function __construct(
        public readonly string $id,
        public readonly ProfileType $type,
        string $label,
        private readonly Clock $clock,
        private readonly RecordBuffer $buffer,
        private readonly QueryCounter $queries,
        private readonly Redactor $redactor,
        private readonly MemoryProbe $memory,
    ) {
        $this->label = $label;
        $this->startMicro = $clock->microtime();
        $this->startMemMb = $memory->currentMb();
        $this->startedAt = $clock->now();
    }

    public function setLabel(string $label): void
    {
        $this->label = $label;
    }

    public function setMethod(?string $method): void
    {
        $this->method = $method;
    }

    /** @param array<array-key,mixed> $context */
    public function addRecord(string $level, string $channel, string $message, array $context): void
    {
        // Milliseconds elapsed since the profile started.
        $t = ($this->clock->microtime() - $this->startMicro) * 1000;
        $this->buffer->add(new LogRecord(
            $level,
            $channel,
            $message,
            $this->redactor->redact($context),
            round($t, 1),
        ));
    }

    public function recordQuery(string $sql, float $ms): void
    {
        $this->queries->record($sql, $ms);
    }

    public function setException(\Throwable $e): void
    {
        $this->exception = [
            'class' => $e::class,
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ];
    }

    public function finish(?int $status): Profile
    {
        $duration = ($this->clock->microtime() - $this->startMicro) * 1000;

        return new Profile(
            $this->id,
            $this->type,
            $this->startedAt,
            $this->label,
            $status,
            round($duration, 1),
            $this->memory->peakMb(),
            $this->queries->stats(),
            $this->buffer->levelCounts(),
            $this->buffer->all(),
            $this->exception,
            $this->buffer->truncated(),
            $this->method,
            $this->startMemMb,
            $this->memory->currentMb(),
        );
    }
}

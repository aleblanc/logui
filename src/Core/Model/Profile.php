<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Core\Model;

final class Profile
{
    /**
     * @param array<string,int>                                    $levels    level => count
     * @param list<LogRecord>                                      $records
     * @param array{class:string,message:string,trace:string}|null $exception
     */
    public function __construct(
        public readonly string $id,
        public readonly ProfileType $type,
        public readonly \DateTimeImmutable $at,
        public readonly string $label,
        public readonly ?int $status,
        public readonly float $durationMs,
        public readonly float $memPeakMb,
        public readonly QueryStats $queries,
        public readonly array $levels,
        public readonly array $records,
        public readonly ?array $exception,
        public readonly bool $truncated,
        public readonly ?string $method = null,
        public readonly ?float $memStartMb = null,
        public readonly ?float $memEndMb = null,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'at' => $this->at->format(\DateTimeInterface::ATOM),
            'label' => $this->label,
            'status' => $this->status,
            'duration_ms' => $this->durationMs,
            'mem_peak_mb' => $this->memPeakMb,
            'queries' => $this->queries->toArray(),
            'levels' => $this->levels,
            'records' => array_map(static fn (LogRecord $r): array => $r->toArray(), $this->records),
            'exception' => $this->exception,
            'truncated' => $this->truncated,
            'method' => $this->method,
            'mem_start_mb' => $this->memStartMb,
            'mem_end_mb' => $this->memEndMb,
        ];
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        /** @var list<array<string,mixed>> $rawRecords */
        $rawRecords = $data['records'] ?? [];
        /** @var array<string,int> $levels */
        $levels = $data['levels'] ?? [];
        /** @var array{class:string,message:string,trace:string}|null $exception */
        $exception = $data['exception'] ?? null;

        return new self(
            (string) $data['id'],
            ProfileType::from((string) $data['type']),
            new \DateTimeImmutable((string) $data['at']),
            (string) $data['label'],
            isset($data['status']) ? (int) $data['status'] : null,
            (float) $data['duration_ms'],
            (float) $data['mem_peak_mb'],
            QueryStats::fromArray(\is_array($data['queries'] ?? null) ? $data['queries'] : []),
            $levels,
            array_map(static fn (array $r): LogRecord => LogRecord::fromArray($r), $rawRecords),
            $exception,
            (bool) ($data['truncated'] ?? false),
            isset($data['method']) ? (string) $data['method'] : null,
            isset($data['mem_start_mb']) ? (float) $data['mem_start_mb'] : null,
            isset($data['mem_end_mb']) ? (float) $data['mem_end_mb'] : null,
        );
    }
}

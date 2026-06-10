<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Core\Model;

final class QueryStats
{
    /** @param list<array{sql:string,ms:float}> $slow */
    public function __construct(
        public readonly int $count,
        public readonly array $slow,
    ) {
    }

    public static function empty(): self
    {
        return new self(0, []);
    }

    /** @return array{count:int,slow:list<array{sql:string,ms:float}>} */
    public function toArray(): array
    {
        return ['count' => $this->count, 'slow' => $this->slow];
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        /** @var list<array{sql:string,ms:float}> $slow */
        $slow = $data['slow'] ?? [];

        return new self((int) ($data['count'] ?? 0), $slow);
    }
}

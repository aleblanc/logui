<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Core\Model;

final class LogRecord
{
    /** @param array<array-key,mixed> $context */
    public function __construct(
        public readonly string $level,
        public readonly string $channel,
        public readonly string $message,
        public readonly array $context,
        public readonly float $t,
    ) {
    }

    /** @return array{level:string,channel:string,msg:string,context:array<array-key,mixed>,t:float} */
    public function toArray(): array
    {
        return [
            'level' => $this->level,
            'channel' => $this->channel,
            'msg' => $this->message,
            'context' => $this->context,
            't' => $this->t,
        ];
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        /** @var array<array-key,mixed> $context */
        $context = $data['context'] ?? [];

        return new self(
            (string) $data['level'],
            (string) $data['channel'],
            (string) $data['msg'],
            $context,
            (float) $data['t'],
        );
    }
}

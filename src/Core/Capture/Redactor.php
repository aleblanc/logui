<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Core\Capture;

final class Redactor
{
    private const DEFAULT_MAX_DEPTH = 16;

    /** @var list<string> lower-cased keys to mask */
    private array $keys;

    /** @param list<string> $keys */
    public function __construct(
        array $keys,
        private readonly string $mask = '***',
        private readonly int $maxDepth = self::DEFAULT_MAX_DEPTH,
    ) {
        $this->keys = array_map('strtolower', $keys);
    }

    /**
     * @param array<array-key,mixed> $context
     *
     * @return array<array-key,mixed>
     */
    public function redact(array $context): array
    {
        return $this->redactDepth($context, 0);
    }

    /**
     * @param array<array-key,mixed> $context
     *
     * @return array<array-key,mixed>
     */
    private function redactDepth(array $context, int $depth): array
    {
        $out = [];
        foreach ($context as $key => $value) {
            // Only named (string) keys can match a sensitive key; list/integer-keyed values pass through by design.
            if (\is_string($key) && \in_array(strtolower($key), $this->keys, true)) {
                $out[$key] = $this->mask;

                continue;
            }

            if (\is_array($value)) {
                $out[$key] = $depth >= $this->maxDepth ? '[nested]' : $this->redactDepth($value, $depth + 1);

                continue;
            }

            $out[$key] = $value;
        }

        return $out;
    }
}

<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Core\Query\LogQL;

/** A line filter: `|= "text"`, `!= "text"`, `|~ "regex"`, `!~ "regex"`. */
final class LineFilter implements Stage
{
    public const CONTAINS = '|=';
    public const NOT_CONTAINS = '!=';
    public const MATCHES = '|~';
    public const NOT_MATCHES = '!~';

    /** Compiled once for regex operators; null for the plain substring ones. */
    private readonly ?Regex $regex;

    /**
     * @throws SyntaxError when a regex operator is given a pattern that does not compile
     */
    public function __construct(
        public readonly string $operator,
        public readonly string $value,
    ) {
        $isRegex = self::MATCHES === $operator || self::NOT_MATCHES === $operator;
        // Line regexes are unanchored — they match anywhere in the line, as in Loki.
        $this->regex = $isRegex ? new Regex($value, anchored: false) : null;
    }

    public function matches(string $line): bool
    {
        return match ($this->operator) {
            self::CONTAINS => false !== mb_stripos($line, $this->value),
            self::NOT_CONTAINS => false === mb_stripos($line, $this->value),
            // A null result means the engine gave up: reject the record either way, so a broken
            // or catastrophic pattern can never select everything through the negated form.
            self::MATCHES => true === $this->regex?->test($line),
            self::NOT_MATCHES => false === $this->regex?->test($line),
            default => true,
        };
    }
}

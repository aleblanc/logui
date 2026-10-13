<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Core\Query\LogQL;

/**
 * One matcher of a stream selector: `file="dev.log"`, `level!="debug"`, `channel=~"app|request"`.
 *
 * Labels map onto the dimensions LogUI already knows about — `file`, `level`, `channel` — so no
 * label index is needed: the value is read straight from the parsed row.
 */
final class LabelMatcher
{
    public const EQ = '=';
    public const NEQ = '!=';
    public const RE = '=~';
    public const NRE = '!~';

    /** Compiled once for the regex operators; null for the plain comparisons. */
    private readonly ?Regex $regex;

    /**
     * @throws SyntaxError when a regex operator is given a pattern that does not compile
     */
    public function __construct(
        public readonly string $label,
        public readonly string $operator,
        public readonly string $value,
    ) {
        $isRegex = self::RE === $operator || self::NRE === $operator;
        // Selector regexes are anchored, as Loki does.
        $this->regex = $isRegex ? new Regex($value, anchored: true) : null;
    }

    public function matches(string $actual): bool
    {
        return match ($this->operator) {
            self::EQ => 0 === strcasecmp($actual, $this->value),
            self::NEQ => 0 !== strcasecmp($actual, $this->value),
            // Fail closed on an engine error, for the negated form too.
            self::RE => true === $this->regex?->test($actual),
            self::NRE => false === $this->regex?->test($actual),
            default => true,
        };
    }
}

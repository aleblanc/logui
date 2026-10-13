<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Core\Query\LogQL;

/**
 * A post-parse label filter: `| status >= 500`, `| user = "bob"`, `| level != "debug"`.
 *
 * Comparison is numeric when both sides look like numbers, and textual otherwise, so
 * `status >= 500` and `user = "bob"` both behave as the user expects.
 */
final class LabelFilter implements Stage
{
    /** Compiled once for the regex operators; null otherwise. */
    private readonly ?Regex $regex;

    /**
     * @throws SyntaxError when a regex operator is given a pattern that does not compile
     */
    public function __construct(
        public readonly string $label,
        public readonly string $operator,
        public readonly string $value,
    ) {
        $isRegex = '=~' === $operator || '!~' === $operator;
        $this->regex = $isRegex ? new Regex($value, anchored: true) : null;
    }

    public function matches(mixed $actual): bool
    {
        if (null === $actual) {
            return false;
        }

        $left = \is_bool($actual) ? ($actual ? '1' : '0') : (string) (\is_scalar($actual) ? $actual : json_encode($actual));
        $right = $this->value;

        if (is_numeric($left) && is_numeric($right)) {
            return $this->compare((float) $left <=> (float) $right);
        }

        return match ($this->operator) {
            '=', '==' => 0 === strcasecmp($left, $right),
            '!=' => 0 !== strcasecmp($left, $right),
            // Fail closed on an engine error, for the negated form too.
            '=~' => true === $this->regex?->test($left),
            '!~' => false === $this->regex?->test($left),
            default => $this->compare(strcasecmp($left, $right)),
        };
    }

    private function compare(int $ordering): bool
    {
        return match ($this->operator) {
            '=', '==' => 0 === $ordering,
            '!=' => 0 !== $ordering,
            '>' => $ordering > 0,
            '>=' => $ordering >= 0,
            '<' => $ordering < 0,
            '<=' => $ordering <= 0,
            default => true,
        };
    }
}

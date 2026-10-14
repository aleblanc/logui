<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Core\Query;

use Aleblanc\LogUi\Core\Query\LogQL\Regex;
use Aleblanc\LogUi\Core\Storage\PlainLogReader;

/**
 * The plain search box: "show me the lines containing this".
 *
 * LogQL is there for people who already know it. Everyone else wants to type `payment failed` and
 * see the lines that say so — case-insensitively, because nobody remembers whether the
 * application logged `Timeout` or `timeout`. The regular-expression mode is opt-in, so an
 * accidental `.` or `(` in a search term is a literal character rather than a syntax error.
 *
 * The message, its context and its extra are all searched: a request id or a SQL parameter lives
 * in one of the latter two, and having to know which part of the record holds what defeats the
 * point of a grep.
 *
 * @phpstan-import-type Row from PlainLogReader
 */
final class Grep
{
    private readonly ?Regex $regex;

    private readonly string $needle;

    /**
     * @param bool $asRegex true to treat the text as a regular expression instead of a literal
     *
     * @throws LogQL\SyntaxError when $asRegex is set and the pattern does not compile
     */
    public function __construct(string $text, bool $asRegex = false)
    {
        $this->needle = $text;
        // Compiled once here rather than per line, and validated now so a broken pattern is
        // reported as an error on the search instead of silently matching nothing.
        $this->regex = $asRegex ? new Regex($text, false) : null;
    }

    public static function of(string $text, bool $asRegex = false): ?self
    {
        return '' === $text ? null : new self($text, $asRegex);
    }

    /** @param Row $row */
    public function matches(array $row): bool
    {
        foreach ([$row['message'], $row['context'], $row['extra']] as $haystack) {
            if (null === $haystack || '' === $haystack) {
                continue;
            }

            // A null verdict from the regex engine means it gave up; a record is never selected on
            // an error, so only an explicit `true` counts as a hit.
            $hit = null !== $this->regex
                ? true === $this->regex->test($haystack)
                : false !== stripos($haystack, $this->needle);

            if ($hit) {
                return true;
            }
        }

        return false;
    }
}

<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Core\Query\LogQL;

/**
 * A user-supplied regular expression, compiled and validated **once, at parse time**.
 *
 * Users type raw patterns (`|~ "order [0-9]+"`), so this is untrusted input running against every
 * line of a log file. Three things are handled here, each of which was a real defect before:
 *
 *  1. **Invalid patterns are rejected up front.** Compiling `(` on every line emitted a PHP warning
 *     per line — which, in a log viewer, means polluting the very file being read — and the filter
 *     silently misbehaved. The user now gets a syntax error on the query instead.
 *  2. **Failures never invert.** `preg_match()` returns `false` both for "no match" and for an
 *     error (notably a backtrack-limit blow-up on a catastrophic pattern). Treating that as "no
 *     match" made the negated form `!~` match *everything*. {@see test()} returns a tri-state so
 *     both `|~` and `!~` fail closed — a record is never selected on the strength of an error.
 *  3. **Catastrophic backtracking is bounded** by PCRE's `backtrack_limit`, which turns a hang into
 *     an error. The residual cost (a few ms per line) is capped by the query time budget in
 *     {@see \Aleblanc\LogUi\Core\Query\LogExplorer}.
 */
final class Regex
{
    /** Pattern delimiter — a control character, so it can never occur in a typed query. */
    private const DELIMITER = "\x01";

    private readonly string $pattern;

    /**
     * @param string $source   the pattern as written by the user, without delimiters
     * @param bool   $anchored true for stream selectors (Loki anchors them), false for line filters
     *
     * @throws SyntaxError when the pattern does not compile
     */
    public function __construct(string $source, bool $anchored)
    {
        // Delimiter: \x01. Anything printable would collide with real patterns — `{}` in
        // particular looks tempting but breaks every `{n,m}` quantifier, which log regexes are
        // full of (`[0-9]{3}`). A control character cannot be typed into a query, and the
        // (impossible in practice) literal case is escaped anyway.
        $body = str_replace(self::DELIMITER, '\\'.self::DELIMITER, $source);
        $this->pattern = self::DELIMITER
            .($anchored ? '^(?:'.$body.')$' : $body)
            .self::DELIMITER.'i';

        // Compile now, on an empty subject, so a bad pattern fails the query rather than each line.
        if (false === $this->quietly(fn (): false|int => preg_match($this->pattern, ''))) {
            throw new SyntaxError(sprintf('Invalid regular expression: %s', $source));
        }
    }

    /**
     * @return bool|null true = match, false = no match, null = the engine gave up (invalid at
     *                   runtime, or backtrack limit exhausted)
     */
    public function test(string $subject): ?bool
    {
        $result = $this->quietly(fn (): false|int => preg_match($this->pattern, $subject));

        return false === $result ? null : 1 === $result;
    }

    /**
     * Runs a PCRE call without letting its warning escape.
     *
     * The `@` operator is not enough here: a custom error handler — Symfony installs one — is
     * still invoked for silenced errors and may log them. In a log viewer that would mean writing
     * into the very file the user is reading, once per line. Swapping the handler for the duration
     * of the call is explicit and independent of how the host application is configured.
     *
     * @param callable():(false|int) $call
     */
    private function quietly(callable $call): false|int
    {
        set_error_handler(static fn (): bool => true);

        try {
            return $call();
        } finally {
            restore_error_handler();
        }
    }
}

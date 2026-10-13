<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Core\Query\LogQL;

/**
 * Parses the LogQL-lite grammar into a {@see Query}.
 *
 *   query     := selector? stage*
 *   selector  := '{' matcher (',' matcher)* '}'
 *   matcher   := IDENT ('=' | '!=' | '=~' | '!~') STRING
 *   stage     := lineFilter | '|' ('json' | labelFilter)
 *   lineFilter:= ('|=' | '!=' | '|~' | '!~') STRING
 *   labelFilter := IDENT ('=' | '==' | '!=' | '>' | '>=' | '<' | '<=' | '=~' | '!~') (STRING | NUMBER)
 *
 * A query that contains neither `{` nor `|` is treated as plain text — typing `payment` keeps
 * working exactly like the previous `q=` search box, so the field stays approachable.
 */
final class Parser
{
    private string $input = '';
    private int $pos = 0;
    private int $length = 0;

    public function parse(string $query): Query
    {
        $trimmed = trim($query);
        if ('' === $trimmed) {
            return new Query([], [], $query);
        }

        // Backwards-compatible shortcut: bare text means "the line contains this".
        if (!str_contains($trimmed, '{') && !str_contains($trimmed, '|')
            && !str_starts_with($trimmed, '!=') && !str_starts_with($trimmed, '!~')) {
            return new Query([], [new LineFilter(LineFilter::CONTAINS, $trimmed)], $query);
        }

        $this->input = $trimmed;
        $this->pos = 0;
        $this->length = \strlen($trimmed);

        $selectors = '{' === $this->peek() ? $this->parseSelector() : [];
        $stages = $this->parseStages();

        $this->skipSpace();
        if ($this->pos < $this->length) {
            throw new SyntaxError(sprintf('Unexpected "%s" at position %d.', $this->peek(), $this->pos), $this->pos);
        }

        return new Query($selectors, $stages, $query);
    }

    /** @return list<LabelMatcher> */
    private function parseSelector(): array
    {
        $this->expect('{');
        $matchers = [];

        $this->skipSpace();
        if ('}' === $this->peek()) {
            ++$this->pos;

            return $matchers;
        }

        while (true) {
            $this->skipSpace();
            $label = $this->readIdentifier('a label name');
            $operator = $this->readMatcherOperator();
            $value = $this->readString();

            $matchers[] = new LabelMatcher($label, $operator, $value);

            $this->skipSpace();
            $next = $this->peek();
            if (',' === $next) {
                ++$this->pos;
                continue;
            }
            if ('}' === $next) {
                ++$this->pos;
                break;
            }

            throw new SyntaxError(sprintf('Expected "," or "}" at position %d.', $this->pos), $this->pos);
        }

        return $matchers;
    }

    /** @return list<Stage> */
    private function parseStages(): array
    {
        $stages = [];

        while (true) {
            $this->skipSpace();
            if ($this->pos >= $this->length) {
                break;
            }

            $two = substr($this->input, $this->pos, 2);

            if (\in_array($two, [LineFilter::CONTAINS, LineFilter::MATCHES, LineFilter::NOT_CONTAINS, LineFilter::NOT_MATCHES], true)) {
                $this->pos += 2;
                $stages[] = new LineFilter($two, $this->readString());
                continue;
            }

            if ('|' === $this->peek()) {
                ++$this->pos;
                $stages[] = $this->parsePipeStage();
                continue;
            }

            break;
        }

        return $stages;
    }

    private function parsePipeStage(): Stage
    {
        $this->skipSpace();
        $start = $this->pos;
        $name = $this->readIdentifier('a parser name or a label');

        if ('json' === strtolower($name)) {
            return new JsonParser();
        }

        if (\in_array(strtolower($name), ['logfmt', 'pattern', 'regexp', 'unwrap', 'line_format', 'label_format'], true)) {
            throw new SyntaxError(sprintf('"%s" is not supported by LogUI — only "| json" is available.', $name), $start);
        }

        $operator = $this->readFilterOperator();
        $value = $this->readValue();

        return new LabelFilter($name, $operator, $value);
    }

    private function readMatcherOperator(): string
    {
        $this->skipSpace();
        foreach ([LabelMatcher::RE, LabelMatcher::NRE, LabelMatcher::NEQ] as $two) {
            if (str_starts_with(substr($this->input, $this->pos), $two)) {
                $this->pos += 2;

                return $two;
            }
        }
        if ('=' === $this->peek()) {
            ++$this->pos;

            return LabelMatcher::EQ;
        }

        throw new SyntaxError(sprintf('Expected a comparison operator at position %d.', $this->pos), $this->pos);
    }

    private function readFilterOperator(): string
    {
        $this->skipSpace();
        foreach (['>=', '<=', '==', '!=', '=~', '!~'] as $two) {
            if (str_starts_with(substr($this->input, $this->pos), $two)) {
                $this->pos += 2;

                return $two;
            }
        }
        $one = $this->peek();
        if (\in_array($one, ['=', '>', '<'], true)) {
            ++$this->pos;

            return $one;
        }

        throw new SyntaxError(sprintf('Expected a comparison operator at position %d.', $this->pos), $this->pos);
    }

    private function readIdentifier(string $expected): string
    {
        $this->skipSpace();
        $start = $this->pos;
        while ($this->pos < $this->length && 1 === preg_match('/[A-Za-z0-9_.\-]/', $this->input[$this->pos])) {
            ++$this->pos;
        }

        if ($this->pos === $start) {
            throw new SyntaxError(sprintf('Expected %s at position %d.', $expected, $this->pos), $this->pos);
        }

        return substr($this->input, $start, $this->pos - $start);
    }

    /** A quoted string, with `\"` and `\\` escapes. */
    private function readString(): string
    {
        $this->skipSpace();
        $quote = $this->peek();
        if ('"' !== $quote && "'" !== $quote) {
            throw new SyntaxError(sprintf('Expected a quoted value at position %d.', $this->pos), $this->pos);
        }
        ++$this->pos;

        $value = '';
        while ($this->pos < $this->length) {
            $c = $this->input[$this->pos];
            if ('\\' === $c && $this->pos + 1 < $this->length) {
                $value .= $this->input[$this->pos + 1];
                $this->pos += 2;
                continue;
            }
            if ($c === $quote) {
                ++$this->pos;

                return $value;
            }
            $value .= $c;
            ++$this->pos;
        }

        throw new SyntaxError('Unterminated string — a quote is missing.', $this->pos);
    }

    /** A label filter value: quoted string, or a bare number/word. */
    private function readValue(): string
    {
        $this->skipSpace();
        $c = $this->peek();
        if ('"' === $c || "'" === $c) {
            return $this->readString();
        }

        $start = $this->pos;
        while ($this->pos < $this->length && 1 === preg_match('/[A-Za-z0-9_.\-+]/', $this->input[$this->pos])) {
            ++$this->pos;
        }
        if ($this->pos === $start) {
            throw new SyntaxError(sprintf('Expected a value at position %d.', $this->pos), $this->pos);
        }

        return substr($this->input, $start, $this->pos - $start);
    }

    private function expect(string $char): void
    {
        if ($this->peek() !== $char) {
            throw new SyntaxError(sprintf('Expected "%s" at position %d.', $char, $this->pos), $this->pos);
        }
        ++$this->pos;
    }

    private function peek(): string
    {
        return $this->pos < $this->length ? $this->input[$this->pos] : '';
    }

    private function skipSpace(): void
    {
        while ($this->pos < $this->length && ' ' === $this->input[$this->pos]) {
            ++$this->pos;
        }
    }
}

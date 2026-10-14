<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Tests\Core\Query\LogQL;

use Aleblanc\LogUi\Core\Query\LogQL\Evaluator;
use Aleblanc\LogUi\Core\Query\LogQL\Parser;
use Aleblanc\LogUi\Core\Query\LogQL\SyntaxError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Regexes in a query are untrusted input evaluated against every line of a log file.
 *
 * @phpstan-import-type Row from \Aleblanc\LogUi\Core\Storage\PlainLogReader
 */
final class RegexSafetyTest extends TestCase
{
    /** @return Row */
    private function row(string $message = 'Payment failed for order 42'): array
    {
        return [
            'date' => '2026-10-13T12:00:00+00:00',
            'ts' => 1_791_892_800_000,
            'ts_inferred' => false,
            'file' => 'var/log/dev.log',
            'channel' => 'request',
            'level' => 'error',
            'message' => $message,
            'context' => null,
            'extra' => null,
            'type' => 'log',
            'profile' => null,
            'offset' => 0,
        ];
    }

    #[DataProvider('brokenPatterns')]
    public function test_a_broken_regex_is_rejected_when_the_query_is_parsed(string $query): void
    {
        // Rejected up front: the user is told, instead of every line silently misbehaving
        // and emitting a PHP warning into the very log file being read.
        $this->expectException(SyntaxError::class);
        (new Parser())->parse($query);
    }

    /** @return \Generator<string,array{string}> */
    public static function brokenPatterns(): \Generator
    {
        yield 'line filter, unclosed group' => ['|~ "("'];
        yield 'negated line filter' => ['!~ "("'];
        yield 'unclosed class' => ['|~ "[a-"'];
        yield 'dangling quantifier' => ['|~ "*"'];
        yield 'stream selector' => ['{level=~"("}'];
        yield 'negated stream selector' => ['{level!~"("}'];
        yield 'label filter' => ['| status =~ "("'];
    }

    public function test_a_broken_regex_emits_no_php_warning(): void
    {
        $warnings = [];
        set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });

        try {
            (new Parser())->parse('|~ "("');
            self::fail('the pattern should have been rejected');
        } catch (SyntaxError) {
            // expected
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $warnings, 'parsing a bad pattern must not emit PHP warnings');
    }

    /**
     * A catastrophic pattern makes PCRE give up (backtrack limit). That failure must never be
     * read as "no match" by a negated filter, or `!~` would select every record.
     */
    public function test_a_catastrophic_pattern_never_selects_everything(): void
    {
        $subject = str_repeat('a', 40).'!';
        $evaluator = new Evaluator((new Parser())->parse('!~ "(a+)+$"'));

        self::assertFalse(
            $evaluator->matches($this->row($subject)),
            'an engine failure must reject the record, not select it',
        );
    }

    public function test_a_catastrophic_pattern_does_not_match_either(): void
    {
        $subject = str_repeat('a', 40).'!';
        $evaluator = new Evaluator((new Parser())->parse('|~ "(a+)+$"'));

        self::assertFalse($evaluator->matches($this->row($subject)));
    }

    public function test_valid_regexes_still_work_both_ways(): void
    {
        $positive = new Evaluator((new Parser())->parse('|~ "order [0-9]+"'));
        $negative = new Evaluator((new Parser())->parse('!~ "order [0-9]+"'));

        self::assertTrue($positive->matches($this->row()));
        self::assertFalse($negative->matches($this->row()));

        self::assertFalse($positive->matches($this->row('nothing here')));
        self::assertTrue($negative->matches($this->row('nothing here')));
    }

    public function test_a_brace_in_a_pattern_does_not_break_the_delimiter(): void
    {
        // Braces were the obvious delimiter choice and would have silently broken every
        // `{n,m}` quantifier — which is why the delimiter is a control character instead.
        $evaluator = new Evaluator((new Parser())->parse('|~ "a{2}"'));

        self::assertTrue($evaluator->matches($this->row('aa')));
        self::assertFalse($evaluator->matches($this->row('ab')));
    }
}

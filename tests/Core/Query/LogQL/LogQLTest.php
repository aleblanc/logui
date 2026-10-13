<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Tests\Core\Query\LogQL;

use Aleblanc\LogUi\Core\Query\LogQL\Evaluator;
use Aleblanc\LogUi\Core\Query\LogQL\JsonParser;
use Aleblanc\LogUi\Core\Query\LogQL\LabelFilter;
use Aleblanc\LogUi\Core\Query\LogQL\LineFilter;
use Aleblanc\LogUi\Core\Query\LogQL\Parser;
use Aleblanc\LogUi\Core\Query\LogQL\SyntaxError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-import-type Row from \Aleblanc\LogUi\Core\Storage\PlainLogReader
 */
final class LogQLTest extends TestCase
{
    private const CONTEXT = '{"status":500,"user":{"id":7,"name":"bob"}}';

    /** @return Row */
    private function row(
        string $level = 'error',
        string $message = 'Payment failed for order 42',
        ?string $context = self::CONTEXT,
    ): array {
        return [
            'date' => '2026-10-13T12:00:00+00:00',
            'ts' => 1_791_892_800_000,
            'ts_inferred' => false,
            'file' => 'var/log/dev.log',
            'channel' => 'request',
            'level' => $level,
            'message' => $message,
            'context' => $context,
            'extra' => null,
        ];
    }

    private function evaluate(string $query, ?string $context = self::CONTEXT): bool
    {
        return (new Evaluator((new Parser())->parse($query)))->matches($this->row(context: $context));
    }

    // ------------------------------------------------------------ parsing

    public function test_bare_text_stays_a_substring_search(): void
    {
        $query = (new Parser())->parse('payment');

        self::assertSame([], $query->selectors);
        self::assertCount(1, $query->stages);
        self::assertInstanceOf(LineFilter::class, $query->stages[0]);
        self::assertTrue($this->evaluate('payment'));
        self::assertFalse($this->evaluate('refund'));
    }

    public function test_empty_query_selects_everything(): void
    {
        self::assertTrue((new Parser())->parse('   ')->isEmpty());
        self::assertTrue($this->evaluate(''));
    }

    public function test_full_pipeline_is_parsed(): void
    {
        $query = (new Parser())->parse('{file="dev.log", level="error"} |= "payment" != "health" | json | status >= 500');

        self::assertCount(2, $query->selectors);
        self::assertCount(4, $query->stages);
        self::assertInstanceOf(LineFilter::class, $query->stages[0]);
        self::assertInstanceOf(LineFilter::class, $query->stages[1]);
        self::assertInstanceOf(JsonParser::class, $query->stages[2]);
        self::assertInstanceOf(LabelFilter::class, $query->stages[3]);
    }

    #[DataProvider('invalidQueries')]
    public function test_invalid_queries_report_a_position(string $query): void
    {
        $this->expectException(SyntaxError::class);
        (new Parser())->parse($query);
    }

    /** @return \Generator<string,array{string}> */
    public static function invalidQueries(): \Generator
    {
        yield 'unclosed selector' => ['{file="a.log"'];
        yield 'missing operator' => ['{file "a.log"}'];
        yield 'missing value' => ['{file=}'];
        yield 'unterminated string' => ['{file="a.log}'];
        yield 'dangling filter' => ['{file="a.log"} |='];
        yield 'unsupported parser' => ['{file="a.log"} | logfmt'];
        yield 'trailing junk' => ['{file="a.log"} oops'];
    }

    public function test_unsupported_parser_names_are_explained(): void
    {
        try {
            (new Parser())->parse('| logfmt');
            self::fail('logfmt should be rejected');
        } catch (SyntaxError $e) {
            self::assertStringContainsString('only "| json"', $e->getMessage());
        }
    }

    // ------------------------------------------------------------ stream selector

    public function test_selector_equality_and_negation(): void
    {
        self::assertTrue($this->evaluate('{level="error"}'));
        self::assertFalse($this->evaluate('{level="info"}'));
        self::assertTrue($this->evaluate('{level!="info"}'));
        self::assertFalse($this->evaluate('{level!="error"}'));
    }

    public function test_selector_regex(): void
    {
        self::assertTrue($this->evaluate('{channel=~"request|app"}'));
        self::assertFalse($this->evaluate('{channel=~"doctrine|mailer"}'));
        self::assertTrue($this->evaluate('{channel!~"doctrine"}'));
    }

    public function test_selector_is_anchored_like_loki(): void
    {
        // "requ" must not match the whole value "request": selectors are anchored.
        self::assertFalse($this->evaluate('{channel=~"requ"}'));
        self::assertTrue($this->evaluate('{channel=~"requ.*"}'));
    }

    public function test_selector_on_file_label(): void
    {
        self::assertTrue($this->evaluate('{file="var/log/dev.log"}'));
        self::assertFalse($this->evaluate('{file="var/log/prod.log"}'));
    }

    public function test_several_matchers_are_combined_with_and(): void
    {
        self::assertTrue($this->evaluate('{level="error", channel="request"}'));
        self::assertFalse($this->evaluate('{level="error", channel="doctrine"}'));
    }

    // ------------------------------------------------------------ line filters

    public function test_line_filters(): void
    {
        self::assertTrue($this->evaluate('|= "payment"'));
        self::assertTrue($this->evaluate('|= "PAYMENT"'), 'line filters are case-insensitive');
        self::assertFalse($this->evaluate('|= "refund"'));
        self::assertTrue($this->evaluate('!= "refund"'));
        self::assertTrue($this->evaluate('|~ "order [0-9]+"'));
        self::assertFalse($this->evaluate('|~ "^order"'), 'line regexes are unanchored but still positional');
        self::assertTrue($this->evaluate('!~ "^refund"'));
    }

    public function test_line_filter_sees_the_context(): void
    {
        // The status lives in the JSON context, not in the message.
        self::assertTrue($this->evaluate('|= "500"'));
    }

    public function test_line_filters_chain_as_and(): void
    {
        self::assertTrue($this->evaluate('|= "payment" |= "order"'));
        self::assertFalse($this->evaluate('|= "payment" |= "refund"'));
    }

    // ------------------------------------------------------------ label filters

    public function test_numeric_label_filter(): void
    {
        self::assertTrue($this->evaluate('| json | status >= 500'));
        self::assertTrue($this->evaluate('| json | status = 500'));
        self::assertFalse($this->evaluate('| json | status > 500'));
        self::assertFalse($this->evaluate('| json | status < 500'));
        self::assertTrue($this->evaluate('| json | status != 404'));
    }

    public function test_nested_json_is_reachable_with_dots(): void
    {
        self::assertTrue($this->evaluate('| json | user.id = 7'));
        self::assertTrue($this->evaluate('| json | user.name = "bob"'));
        self::assertFalse($this->evaluate('| json | user.name = "alice"'));
    }

    public function test_label_filter_works_without_explicit_json(): void
    {
        self::assertTrue($this->evaluate('| status >= 500'));
    }

    public function test_label_filter_on_a_row_dimension(): void
    {
        self::assertTrue($this->evaluate('| level = "error"'));
        self::assertFalse($this->evaluate('| level = "info"'));
    }

    public function test_missing_label_never_matches(): void
    {
        self::assertFalse($this->evaluate('| json | nope = "x"'));
        self::assertFalse($this->evaluate('| json | nope != "x"'), 'an absent label cannot satisfy a filter');
    }

    public function test_row_without_context_is_handled(): void
    {
        self::assertFalse($this->evaluate('| json | status >= 500', context: null));
        self::assertTrue($this->evaluate('|= "payment"', context: null));
    }

    // ------------------------------------------------------------ streaming

    public function test_filter_streams_only_matching_rows(): void
    {
        $rows = [
            $this->row(level: 'error', message: 'boom'),
            $this->row(level: 'info', message: 'fine'),
            $this->row(level: 'error', message: 'boom again'),
        ];

        $evaluator = new Evaluator((new Parser())->parse('{level="error"}'));
        $kept = iterator_to_array($evaluator->filter($rows), false);

        self::assertCount(2, $kept);
        self::assertSame('boom', $kept[0]['message']);
    }

    public function test_empty_query_passes_everything_through(): void
    {
        $rows = [$this->row(), $this->row(level: 'info')];
        $evaluator = new Evaluator((new Parser())->parse(''));

        self::assertCount(2, iterator_to_array($evaluator->filter($rows), false));
    }
}

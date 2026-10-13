<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Tests\Core\Query;

use Aleblanc\LogUi\Core\Query\Grep;
use Aleblanc\LogUi\Core\Query\LogExplorer;
use Aleblanc\LogUi\Core\Query\LogQL\SyntaxError;
use Aleblanc\LogUi\Core\Query\Selection;
use Aleblanc\LogUi\Core\Storage\MergedLogReader;
use Aleblanc\LogUi\Core\Storage\PlainLogReader;
use Aleblanc\LogUi\Core\Time\TimestampParser;
use PHPUnit\Framework\TestCase;

/**
 * The plain search box — the one people reach for before they ever meet LogQL.
 *
 * @phpstan-import-type Row from PlainLogReader
 */
final class GrepTest extends TestCase
{
    private const BASE = '2026-10-13 12:00:00';

    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        $this->files = [];
    }

    /** @return Row */
    private function row(string $message, ?string $context = null, string $level = 'error'): array
    {
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

    public function test_a_plain_search_is_case_insensitive(): void
    {
        // Nobody remembers whether the application wrote Timeout or timeout.
        $grep = new Grep('timeout');

        self::assertTrue($grep->matches($this->row('Gateway Timeout on /pay')));
        self::assertTrue($grep->matches($this->row('gateway timeout')));
        self::assertFalse($grep->matches($this->row('everything is fine')));
    }

    public function test_a_plain_search_treats_regex_syntax_as_literal_text(): void
    {
        // Typing an id like `user(42)` must not explode, and must not match something else.
        $grep = new Grep('user(42)');

        self::assertTrue($grep->matches($this->row('deleting user(42) now')));
        self::assertFalse($grep->matches($this->row('deleting user42 now')));
    }

    public function test_a_plain_search_never_throws_on_broken_regex_syntax(): void
    {
        $grep = new Grep('([a-');

        self::assertTrue($grep->matches($this->row('weird ([a- input')));
    }

    public function test_the_context_is_searched_too(): void
    {
        // A request id or a SQL parameter lives in the context; having to know which half of the
        // record holds what would defeat the point.
        $grep = new Grep('abc123');

        self::assertTrue($grep->matches($this->row('Matched route', '{"request_id":"abc123"}')));
        self::assertFalse($grep->matches($this->row('Matched route', '{"request_id":"zzz"}')));
        self::assertFalse($grep->matches($this->row('Matched route', null)));
    }

    public function test_regex_mode_matches_patterns(): void
    {
        $grep = new Grep('order [0-9]{2,}', asRegex: true);

        self::assertTrue($grep->matches($this->row('shipped order 4211')));
        self::assertFalse($grep->matches($this->row('shipped order 7')));
    }

    public function test_regex_mode_is_case_insensitive_too(): void
    {
        self::assertTrue((new Grep('^payment', asRegex: true))->matches($this->row('Payment refused')));
    }

    public function test_a_broken_regex_is_rejected_when_the_search_is_built(): void
    {
        // Reported once, as an error on the search — not silently, once per line.
        $this->expectException(SyntaxError::class);
        new Grep('([a-', asRegex: true);
    }

    public function test_a_catastrophic_regex_never_selects_everything(): void
    {
        $grep = new Grep('(a+)+$', asRegex: true);

        self::assertFalse($grep->matches($this->row(str_repeat('a', 40).'!')));
    }

    public function test_an_empty_search_is_no_search_at_all(): void
    {
        self::assertNull(Grep::of(''));
        self::assertInstanceOf(Grep::class, Grep::of('x'));
        self::assertTrue((new Selection())->isEmpty());
        self::assertFalse((new Selection([], [], Grep::of('x')))->isEmpty());
    }

    // ---------------------------------------------------------------- end to end

    /** @return array<string,string> */
    private function fixture(): array
    {
        $path = tempnam(sys_get_temp_dir(), 'logui_grep_').'.log';
        $this->files[] = $path;

        $base = strtotime(self::BASE.' UTC');
        $records = [
            [0, 'request', 'INFO', 'Matched route "home"'],
            [10, 'request', 'ERROR', 'Payment failed for order 4211'],
            [20, 'doctrine', 'DEBUG', 'SELECT * FROM orders WHERE id = 4211'],
            [30, 'request', 'ERROR', 'Gateway TIMEOUT on /pay'],
        ];

        $lines = [];
        foreach ($records as [$second, $channel, $level, $message]) {
            $lines[] = sprintf('[%s] %s.%s: %s', gmdate('Y-m-d\TH:i:sP', $base + $second), $channel, $level, $message);
        }
        file_put_contents($path, implode("\n", $lines)."\n");

        return ['app.log' => $path];
    }

    private function explorer(): LogExplorer
    {
        return new LogExplorer(new MergedLogReader(new PlainLogReader(new TimestampParser('UTC'))));
    }

    private function at(int $second): int
    {
        return (strtotime(self::BASE.' UTC') + $second) * 1000;
    }

    public function test_the_search_narrows_the_results(): void
    {
        $result = $this->explorer()->query(
            $this->fixture(), '', $this->at(0), $this->at(60), 100, 0,
            new Selection([], [], Grep::of('4211')),
        );

        self::assertSame(2, $result['total']);
    }

    public function test_the_search_combines_with_the_facets(): void
    {
        $result = $this->explorer()->query(
            $this->fixture(), '', $this->at(0), $this->at(60), 100, 0,
            new Selection(['error'], [], Grep::of('4211')),
        );

        self::assertSame(1, $result['total']);
        self::assertStringContainsString('Payment failed', $result['rows'][0]['message']);
    }

    /**
     * The search is part of the question being asked, so unlike the level/channel checkboxes it
     * *must* be reflected in the facet counts — otherwise the panel would advertise levels that
     * the visible result set does not contain.
     */
    public function test_the_search_narrows_the_facet_counts(): void
    {
        $histogram = $this->explorer()->histogram(
            $this->fixture(), '', $this->at(0), $this->at(60),
            new Selection([], [], Grep::of('4211')),
        );

        self::assertSame(['error' => 1, 'debug' => 1], $histogram['facets']['level']);
        self::assertSame(2, $histogram['matched']);
    }

    public function test_ticked_facets_still_do_not_narrow_the_counts(): void
    {
        $histogram = $this->explorer()->histogram(
            $this->fixture(), '', $this->at(0), $this->at(60),
            new Selection(['error'], [], Grep::of('4211')),
        );

        self::assertSame(['error' => 1, 'debug' => 1], $histogram['facets']['level'], 'the facet list stays complete');
        self::assertSame(1, $histogram['total'], 'but the chart only plots the ticked level');
    }
}

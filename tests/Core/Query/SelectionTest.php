<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Tests\Core\Query;

use Aleblanc\LogUi\Core\Query\LogExplorer;
use Aleblanc\LogUi\Core\Query\Selection;
use Aleblanc\LogUi\Core\Storage\MergedLogReader;
use Aleblanc\LogUi\Core\Storage\PlainLogReader;
use Aleblanc\LogUi\Core\Time\TimestampParser;
use PHPUnit\Framework\TestCase;

/**
 * The checkbox/select filters, and the facet counts the UI builds them from.
 */
final class SelectionTest extends TestCase
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

    private function at(int $second): int
    {
        return (strtotime(self::BASE.' UTC') + $second) * 1000;
    }

    private function explorer(): LogExplorer
    {
        return new LogExplorer(new MergedLogReader(new PlainLogReader(new TimestampParser('UTC'))));
    }

    /**
     * @param list<array{int,string,string,string}> $records [second, channel, level, message]
     *
     * @return array<string,string>
     */
    private function sources(array $records): array
    {
        $path = tempnam(sys_get_temp_dir(), 'logui_selection_').'.log';
        $this->files[] = $path;

        $base = strtotime(self::BASE.' UTC');
        $lines = [];
        foreach ($records as [$second, $channel, $level, $message]) {
            $lines[] = sprintf('[%s] %s.%s: %s', gmdate('Y-m-d\TH:i:sP', $base + $second), $channel, strtoupper($level), $message);
        }
        file_put_contents($path, implode("\n", $lines)."\n");

        return ['app.log' => $path];
    }

    /** @return array<string,string> */
    private function fixture(): array
    {
        return $this->sources([
            [0, 'request', 'info', 'GET /'],
            [10, 'request', 'error', 'boom'],
            [20, 'doctrine', 'debug', 'SELECT 1'],
            [30, 'doctrine', 'error', 'deadlock'],
            [40, 'security', 'warning', 'bad password'],
        ]);
    }

    public function test_an_empty_selection_filters_nothing(): void
    {
        $selection = new Selection();

        self::assertTrue($selection->isEmpty());

        $result = $this->explorer()->query($this->fixture(), '', $this->at(0), $this->at(60), 100, 0, $selection);
        self::assertSame(5, $result['total']);
    }

    public function test_a_level_selection_keeps_only_those_levels(): void
    {
        $result = $this->explorer()->query(
            $this->fixture(), '', $this->at(0), $this->at(60), 100, 0,
            new Selection(['error']),
        );

        self::assertSame(2, $result['total']);
        self::assertSame(['error', 'error'], array_column($result['rows'], 'level'));
    }

    public function test_several_levels_are_ored_together(): void
    {
        $result = $this->explorer()->query(
            $this->fixture(), '', $this->at(0), $this->at(60), 100, 0,
            new Selection(['error', 'warning']),
        );

        self::assertSame(3, $result['total']);
    }

    public function test_level_matching_ignores_case(): void
    {
        // The UI sends back whatever the facet list contained; nothing guarantees its case.
        $result = $this->explorer()->query(
            $this->fixture(), '', $this->at(0), $this->at(60), 100, 0,
            new Selection(['ERROR']),
        );

        self::assertSame(2, $result['total']);
    }

    public function test_level_and_channel_are_anded(): void
    {
        $result = $this->explorer()->query(
            $this->fixture(), '', $this->at(0), $this->at(60), 100, 0,
            new Selection(['error'], ['doctrine']),
        );

        self::assertSame(1, $result['total']);
        self::assertSame('deadlock', $result['rows'][0]['message']);
    }

    public function test_the_selection_is_anded_with_the_logql_query(): void
    {
        $result = $this->explorer()->query(
            $this->fixture(), '|= "boom"', $this->at(0), $this->at(60), 100, 0,
            new Selection(['error']),
        );

        self::assertSame(1, $result['total']);

        $none = $this->explorer()->query(
            $this->fixture(), '|= "boom"', $this->at(0), $this->at(60), 100, 0,
            new Selection(['debug']),
        );

        self::assertSame(0, $none['total']);
    }

    public function test_facets_report_what_the_query_matched(): void
    {
        $histogram = $this->explorer()->histogram($this->fixture(), '', $this->at(0), $this->at(60));

        self::assertSame(['error' => 2, 'info' => 1, 'debug' => 1, 'warning' => 1], $histogram['facets']['level']);
        self::assertSame(['request' => 2, 'doctrine' => 2, 'security' => 1], $histogram['facets']['channel']);
        self::assertSame(['app.log' => 5], $histogram['facets']['file']);
    }

    public function test_facets_are_ordered_by_frequency(): void
    {
        $histogram = $this->explorer()->histogram($this->fixture(), '', $this->at(0), $this->at(60));

        $counts = array_values($histogram['facets']['channel']);
        $sorted = $counts;
        rsort($sorted);

        self::assertSame($sorted, $counts, 'facets must come back most frequent first');
    }

    /**
     * The facet list must keep showing every level even once one of them is ticked — otherwise
     * ticking `error` would remove every other box and there would be no way back.
     */
    public function test_facet_counts_ignore_the_selection(): void
    {
        $histogram = $this->explorer()->histogram(
            $this->fixture(), '', $this->at(0), $this->at(60),
            new Selection(['error']),
        );

        self::assertSame(['error' => 2, 'info' => 1, 'debug' => 1, 'warning' => 1], $histogram['facets']['level']);
        self::assertSame(5, $histogram['matched'], 'matched counts what the query matched');
        self::assertSame(2, $histogram['total'], 'total counts what the chart actually plots');
    }

    public function test_the_selection_narrows_the_histogram(): void
    {
        $histogram = $this->explorer()->histogram(
            $this->fixture(), '', $this->at(0), $this->at(60),
            new Selection([], ['doctrine']),
        );

        $plotted = array_sum(array_column($histogram['buckets'], 'count'));
        self::assertSame(2, $plotted);
    }

    public function test_facets_narrow_with_the_query(): void
    {
        $histogram = $this->explorer()->histogram($this->fixture(), '{channel="doctrine"}', $this->at(0), $this->at(60));

        self::assertSame(['doctrine' => 2], $histogram['facets']['channel']);
    }
}

<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Core\Query;

use Aleblanc\LogUi\Core\Query\LogQL\Evaluator;
use Aleblanc\LogUi\Core\Query\LogQL\Parser;
use Aleblanc\LogUi\Core\Storage\MergedLogReader;
use Aleblanc\LogUi\Core\Storage\PlainLogReader;
use Aleblanc\LogUi\Core\Storage\TelemetryReader;

/**
 * Ties the pieces together: reads the requested time window across the selected files
 * ({@see MergedLogReader}), filters it with a LogQL-lite query ({@see Evaluator}), and returns
 * either the matching rows or their distribution over time.
 *
 * Everything streams: rows are produced one by one and discarded as soon as they are rejected or
 * counted, so memory stays flat whatever the window.
 *
 * @phpstan-import-type Row from PlainLogReader
 */
final class LogExplorer
{
    /** Hard cap on returned rows, so a broad query can never exhaust memory. */
    public const MAX_ROWS = 1_000;

    /**
     * Wall-clock budget for a single query, in seconds.
     *
     * This is the backstop that keeps an authenticated user from tying up a worker: a very wide
     * window, a hundred files, or a pathological regex (PCRE caps the backtracking, but a few
     * milliseconds per line still adds up) all stop here and come back marked `truncated`.
     */
    public const DEFAULT_BUDGET_SECONDS = 5.0;

    /** How often the deadline is checked — reading the clock per row would itself be a cost. */
    private const BUDGET_CHECK_EVERY = 256;

    /** Target number of histogram bars — the bucket width is derived from the window. */
    private const TARGET_BUCKETS = 60;

    /** Bucket widths offered, from a second to a day (in ms). */
    private const BUCKET_STEPS = [
        1_000, 5_000, 15_000, 30_000,
        60_000, 300_000, 900_000, 1_800_000,
        3_600_000, 10_800_000, 21_600_000, 43_200_000,
        86_400_000,
    ];

    public function __construct(
        private readonly MergedLogReader $merged,
        private readonly Parser $parser = new Parser(),
        private readonly float $budgetSeconds = self::DEFAULT_BUDGET_SECONDS,
    ) {
    }

    /**
     * Matching rows, newest first.
     *
     * @param array<string,string> $sources public ref => absolute path
     *
     * @return array{rows:list<Row>,total:int,truncated:bool,tookMs:float}
     */
    public function query(array $sources, string $logql, int $fromMs, int $toMs, int $limit = self::MAX_ROWS, int $offset = 0, ?Selection $selection = null): array
    {
        $started = microtime(true);
        $evaluator = new Evaluator($this->parser->parse($logql));
        $selection ??= new Selection();

        $rows = [];
        $total = 0;
        $truncated = false;
        $ceiling = min($limit, self::MAX_ROWS);

        $deadline = $started + $this->budgetSeconds;

        foreach ($evaluator->filter($this->read($sources, $fromMs, $toMs)) as $row) {
            if (!$selection->matches($row)) {
                continue;
            }

            ++$total;

            // Keep a bounded window around the requested page: counting continues so the UI can
            // tell how many records matched, but only the page itself is retained.
            if ($total > $offset && \count($rows) < $ceiling) {
                $rows[] = $row;
            }

            if ($total >= self::MAX_ROWS * 10) {
                $truncated = true;
                break;
            }

            if (0 === $total % self::BUDGET_CHECK_EVERY && microtime(true) > $deadline) {
                $truncated = true;
                break;
            }
        }

        // Newest first, as every other LogUI listing.
        $rows = array_reverse($rows);

        return [
            'rows' => $rows,
            'total' => $total,
            'truncated' => $truncated,
            'tookMs' => round((microtime(true) - $started) * 1000, 1),
        ];
    }

    /**
     * Distribution of matching records over time — the implicit `count_over_time` behind the
     * histogram. Counts are broken down by level so the bars can be stacked.
     *
     * The same pass also tallies the facets (which levels, channels and files occur, and how
     * often) that the checkbox filters are built from. They are counted on everything the *query*
     * matches, before the facet selection is applied: otherwise ticking `error` would erase every
     * other level from the list and there would be no way back.
     *
     * @param array<string,string> $sources
     *
     * @return array{buckets:list<array{ts:int,count:int,levels:array<string,int>}>,facets:array{level:array<string,int>,channel:array<string,int>,file:array<string,int>},interval:int,from:int,to:int,total:int,matched:int,truncated:bool,tookMs:float}
     */
    public function histogram(array $sources, string $logql, int $fromMs, int $toMs, ?Selection $selection = null): array
    {
        $started = microtime(true);
        $evaluator = new Evaluator($this->parser->parse($logql));
        $selection ??= new Selection();
        $interval = $this->bucketWidth($fromMs, $toMs);

        /** @var array<int,array{ts:int,count:int,levels:array<string,int>}> $buckets */
        $buckets = [];
        /** @var array{level:array<string,int>,channel:array<string,int>,file:array<string,int>} $facets */
        $facets = ['level' => [], 'channel' => [], 'file' => []];
        $total = 0;
        $matched = 0;
        $seen = 0;
        $truncated = false;
        $deadline = $started + $this->budgetSeconds;

        foreach ($evaluator->filter($this->read($sources, $fromMs, $toMs)) as $row) {
            if (0 === ++$seen % self::BUDGET_CHECK_EVERY && microtime(true) > $deadline) {
                // Counting stops here; the chart shows what was measured and says so.
                $truncated = true;
                break;
            }

            if (!$selection->matchesSearch($row)) {
                continue;
            }

            $level = '' !== $row['level'] ? strtolower($row['level']) : 'other';

            ++$matched;
            $facets['level'][$level] = ($facets['level'][$level] ?? 0) + 1;
            if ('' !== $row['channel']) {
                $facets['channel'][$row['channel']] = ($facets['channel'][$row['channel']] ?? 0) + 1;
            }
            $facets['file'][$row['file']] = ($facets['file'][$row['file']] ?? 0) + 1;

            if (!$selection->matchesFacets($row)) {
                continue;
            }

            $ts = $row['ts'];
            if (null === $ts) {
                continue; // undatable lines cannot be placed on a time axis
            }

            $slot = intdiv($ts - $fromMs, $interval) * $interval + $fromMs;

            if (!isset($buckets[$slot])) {
                $buckets[$slot] = ['ts' => $slot, 'count' => 0, 'levels' => []];
            }
            ++$buckets[$slot]['count'];
            $buckets[$slot]['levels'][$level] = ($buckets[$slot]['levels'][$level] ?? 0) + 1;
            ++$total;
        }

        ksort($buckets);
        foreach ($facets as &$counts) {
            arsort($counts);
        }
        unset($counts);

        return [
            'buckets' => array_values($buckets),
            'facets' => $facets,
            'interval' => $interval,
            'from' => $fromMs,
            'to' => $toMs,
            'total' => $total,
            'matched' => $matched,
            'truncated' => $truncated,
            'tookMs' => round((microtime(true) - $started) * 1000, 1),
        ];
    }

    /**
     * Compiles a query once, so a long-lived live stream doesn't re-parse it on every poll.
     *
     * @throws LogQL\SyntaxError
     */
    public function evaluatorFor(string $logql): Evaluator
    {
        return new Evaluator($this->parser->parse($logql));
    }

    /**
     * Records appended to a file since a byte offset — the live tail.
     *
     * Only the new bytes are read, so following a multi-GB file costs the same as following an
     * empty one. The first line is kept whole: $offset is always a boundary left by a previous
     * call (the file size at that time).
     *
     * @return \Generator<int,Row>
     */
    public function tailFrom(string $ref, string $path, int $offset, Evaluator $evaluator, ?Selection $selection = null): \Generator
    {
        foreach ($evaluator->filter($this->readFrom($ref, $path, $offset)) as $row) {
            if (null === $selection || $selection->matches($row)) {
                yield $row;
            }
        }
    }

    /**
     * @return \Generator<int,Row>
     */
    private function readFrom(string $ref, string $path, int $offset): \Generator
    {
        foreach ($this->merged->readFrom([$ref => $path], $offset) as $row) {
            if (str_contains($row['message'], TelemetryReader::SENTINEL)) {
                continue;
            }

            yield $row;
        }
    }

    /**
     * Bucket width that keeps the chart readable: the smallest round step that yields at most
     * TARGET_BUCKETS bars over the window.
     */
    public function bucketWidth(int $fromMs, int $toMs): int
    {
        $span = max(1, $toMs - $fromMs);
        foreach (self::BUCKET_STEPS as $step) {
            if (intdiv($span, $step) <= self::TARGET_BUCKETS) {
                return $step;
            }
        }

        return (int) max(1, ceil($span / self::TARGET_BUCKETS));
    }

    /**
     * @param array<string,string> $sources
     *
     * @return \Generator<int,Row>
     */
    private function read(array $sources, int $fromMs, int $toMs): \Generator
    {
        foreach ($this->merged->readRange($sources, $fromMs, $toMs) as $row) {
            // LogUI's own telemetry lines belong to the Requests tab, not the log explorer.
            if (str_contains($row['message'], TelemetryReader::SENTINEL)) {
                continue;
            }

            yield $row;
        }
    }
}

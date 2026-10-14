<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Core\Query;

use Aleblanc\LogUi\Core\Model\Profile;
use Aleblanc\LogUi\Core\Query\LogQL\Evaluator;
use Aleblanc\LogUi\Core\Query\LogQL\Parser;
use Aleblanc\LogUi\Core\Storage\MergedLogReader;
use Aleblanc\LogUi\Core\Storage\PlainLogReader;

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
     * The same pass also tallies the facets (which types, levels, channels and files occur, and
     * how often) that the checkbox filters are built from. They are counted on everything the
     * *query* matches, before the facet selection is applied: otherwise ticking `error` would
     * erase every other level from the list and there would be no way back.
     *
     * Requests and commands are rows like the others, and they also feed a small set of request
     * statistics (count, failures, p50/p95 duration, SQL) computed on what passed every filter.
     *
     * @param array<string,string> $sources
     *
     * @return array{buckets:list<array{ts:int,count:int,levels:array<string,int>,requests:int,latency:array{n:int,p50:float,p95:float}|null}>,facets:array{type:array<string,int>,level:array<string,int>,channel:array<string,int>,file:array<string,int>},patterns:list<array{pattern:string,count:int,sample:string,level:string}>,profiles:array{count:int,failed:int,p50:float,p95:float,sql:int},interval:int,from:int,to:int,total:int,matched:int,truncated:bool,tookMs:float}
     */
    public function histogram(array $sources, string $logql, int $fromMs, int $toMs, ?Selection $selection = null, int $top = 20): array
    {
        $started = microtime(true);
        $evaluator = new Evaluator($this->parser->parse($logql));
        $selection ??= new Selection();
        $interval = $this->bucketWidth($fromMs, $toMs);

        /** @var array<int,array{ts:int,count:int,levels:array<string,int>,requests:int,latency:array{n:int,p50:float,p95:float}|null}> $buckets */
        $buckets = [];
        /** @var array<int,list<float>> $perBucket request durations per slot, turned into percentiles after ksort */
        $perBucket = [];
        /** @var array{type:array<string,int>,level:array<string,int>,channel:array<string,int>,file:array<string,int>} $facets */
        $facets = ['type' => [], 'level' => [], 'channel' => [], 'file' => []];
        $total = 0;
        $matched = 0;
        $seen = 0;
        $truncated = false;
        $deadline = $started + $this->budgetSeconds;

        /** @var list<float> $durations */
        $durations = [];
        $failed = 0;
        $sql = 0;
        $patterns = new Patterns();

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
            $facets['type'][$row['type']] = ($facets['type'][$row['type']] ?? 0) + 1;
            $facets['level'][$level] = ($facets['level'][$level] ?? 0) + 1;
            if ('' !== $row['channel']) {
                $facets['channel'][$row['channel']] = ($facets['channel'][$row['channel']] ?? 0) + 1;
            }
            $facets['file'][$row['file']] = ($facets['file'][$row['file']] ?? 0) + 1;

            if (!$selection->matchesFacets($row)) {
                continue;
            }

            // Counted on everything the facets kept, before the pattern narrows — same reason the
            // facet counts are taken before the facet selection: picking one must not erase the rest.
            $patterns->add($row['message'], $level);
            if (!$selection->matchesPattern($row)) {
                continue;
            }

            $profile = $row['profile'];
            if (null !== $profile) {
                $durations[] = (float) $profile['duration_ms'];
                $sql += (int) $profile['sql'];
                if ($profile['failed']) {
                    ++$failed;
                }
            }

            $ts = $row['ts'];
            if (null === $ts) {
                continue; // undatable lines cannot be placed on a time axis
            }

            $slot = intdiv($ts - $fromMs, $interval) * $interval + $fromMs;

            if (!isset($buckets[$slot])) {
                $buckets[$slot] = ['ts' => $slot, 'count' => 0, 'levels' => [], 'requests' => 0, 'latency' => null];
            }
            ++$buckets[$slot]['count'];
            $buckets[$slot]['levels'][$level] = ($buckets[$slot]['levels'][$level] ?? 0) + 1;
            if (null !== $profile) {
                ++$buckets[$slot]['requests'];
                $perBucket[$slot][] = (float) $profile['duration_ms'];
            }
            ++$total;
        }

        ksort($buckets);
        foreach ($buckets as $slot => $_bucket) {
            if (!isset($perBucket[$slot])) {
                continue;
            }
            $slotDurations = $perBucket[$slot];
            sort($slotDurations);
            $buckets[$slot]['latency'] = [
                'n' => \count($slotDurations),
                'p50' => self::percentile($slotDurations, 0.5),
                'p95' => self::percentile($slotDurations, 0.95),
            ];
        }
        foreach ($facets as &$counts) {
            arsort($counts);
        }
        unset($counts);

        sort($durations);

        return [
            'buckets' => array_values($buckets),
            'facets' => $facets,
            'patterns' => $patterns->top($top),
            'profiles' => [
                'count' => \count($durations),
                'failed' => $failed,
                'p50' => self::percentile($durations, 0.5),
                'p95' => self::percentile($durations, 0.95),
                'sql' => $sql,
            ],
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
     * Nearest-rank percentile of an ascending list; 0 when empty.
     *
     * @param list<float> $sorted
     */
    public static function percentile(array $sorted, float $p): float
    {
        $n = \count($sorted);
        if (0 === $n) {
            return 0.0;
        }

        return $sorted[max(0, min($n - 1, (int) ceil($p * $n) - 1))];
    }

    /**
     * The full capture behind a profile row — records included — decoded on demand.
     *
     * The row only carried a header; this reads the second around its timestamp in that one file
     * and decodes the line whose id matches. One request's worth of JSON, never a page's.
     *
     * @return array<string,mixed>|null Profile::toArray() shape, null when not found
     */
    public function profile(string $ref, string $path, int $tsMs, string $id): ?array
    {
        foreach ($this->merged->readRange([$ref => $path], $tsMs - 1_000, $tsMs + 1_000) as $row) {
            $header = $row['profile'];
            if (null === $header || $header['id'] !== $id || null === $row['context']) {
                continue;
            }

            $data = json_decode($row['context'], true);
            if (!\is_array($data)) {
                return null;
            }

            try {
                return Profile::fromArray($data)->toArray();
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * The lines around one record — `n` before, the record itself, and `n` after.
     *
     * The record is located by its byte offset, not its timestamp, so the result is exactly what
     * sits next to it in the file (a stack trace, the request that caused it) regardless of whether
     * neighbours are datable.
     *
     * @return array{rows:list<Row>,pivot:int} `pivot` is the index of the record itself
     */
    public function context(string $ref, string $path, int $offset, int $n): array
    {
        $before = [];
        foreach ($this->merged->readBetween($path, max(0, $offset - 65536), $offset) as $row) {
            $row['file'] = $ref;
            $before[] = $row;
        }
        $before = \array_slice($before, -$n);

        $after = [];
        foreach ($this->merged->readBetween($path, $offset, \PHP_INT_MAX) as $row) {
            $row['file'] = $ref;
            $after[] = $row;
            if (\count($after) >= $n + 1) {
                break;
            }
        }

        return ['rows' => array_merge($before, $after), 'pivot' => \count($before)];
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
        yield from $this->merged->readFrom([$ref => $path], $offset);
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
    /**
     * @param array<string,string> $sources
     *
     * @return \Generator<int,Row>
     */
    private function read(array $sources, int $fromMs, int $toMs): \Generator
    {
        yield from $this->merged->readRange($sources, $fromMs, $toMs);
    }
}

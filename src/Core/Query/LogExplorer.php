<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Core\Query;

use Aleblanc\LogUi\Core\Model\Profile;
use Aleblanc\LogUi\Core\Query\LogQL\Evaluator;
use Aleblanc\LogUi\Core\Query\LogQL\Parser;
use Aleblanc\LogUi\Core\Stats\Timings;
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

    /**
     * Per-file byte cap for the explorer's reads.
     *
     * The wall-clock budget is what bounds the work here; the reader's default cap (8 MB, a
     * safety net for callers without a deadline) would silently cut a single day of a busy log
     * — an 18 MB `dev.log` came back with two thirds of its lines and no `truncated` flag.
     */
    private const RANGE_BYTES = 1 << 30;

    /** How often the deadline is checked — reading the clock per row would itself be a cost. */
    private const BUDGET_CHECK_EVERY = 256;

    /**
     * Throughput assumed before a request has measured its own (header-only counting on a
     * Raspberry Pi with an SD card); deliberately on the low side, so the first guess samples a
     * little more than needed rather than running out of budget.
     */
    private const ASSUMED_BYTES_PER_SECOND = 8_000_000;

    /** Kept back from the budget for merging, aggregating and encoding the answer. */
    private const BUDGET_MARGIN_SECONDS = 0.5;

    /** Unindexed bytes below this are a line being written, not a backlog to warm up for. */
    private const WARM_UP_BYTES = 65_536;

    /** Share of the budget an index catch-up may use; the rest reads what indexes do not cover. */
    private const CATCH_UP_SHARE = 0.5;

    /** Never read less than this share of a file: below it, the bars are mostly noise. */
    private const MIN_SAMPLE = 0.02;

    /** One info/debug record in this many is parsed in full for the patterns panel. */
    private const PATTERN_EVERY = 8;

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
        private readonly ?MinuteIndexStore $indexes = null,
    ) {
    }

    /**
     * Matching rows, newest first.
     *
     * @param array<string,string> $sources public ref => absolute path
     *
     * @return array{rows:list<Row>,total:int,truncated:bool,tookMs:float}
     */
    public function query(array $sources, string $logql, int $fromMs, int $toMs, int $limit = self::MAX_ROWS, int $offset = 0, ?Selection $selection = null, ?Timings $timings = null): array
    {
        $started = microtime(true);
        $timings ??= new Timings();
        $evaluator = $timings->measure('parse', fn (): Evaluator => new Evaluator($this->parser->parse($logql)));
        $selection ??= new Selection();

        $rows = [];
        $total = 0;
        $truncated = false;
        $ceiling = min($limit, self::MAX_ROWS);

        $deadline = $started + $this->budgetSeconds;

        $scanStarted = microtime(true);
        foreach ($evaluator->filter($this->read($sources, $fromMs, $toMs, $timings, $deadline)) as $row) {
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

        $this->closeScan($timings, $scanStarted);
        $truncated = $truncated || $timings->wasBudgetHit();

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
     * Two ways to get there:
     *  - **No text search** (no LogQL, no search term, no picked pattern) — the default view, and
     *    the one that spans days. Files are counted one by one from their line headers
     *    ({@see histogramByFile()}): each file's incremental index answers, and when what is left
     *    cannot be read within the budget, files are sampled and the counts extrapolated
     *    (`sampled` says which share was read).
     *  - **Text search** — every line is parsed and matched, across the merged sources.
     *
     * @param array<string,string> $sources
     *
     * @return array{buckets:list<array{ts:int,count:int,levels:array<string,int>,requests:int,latency:array{n:int,p50:float,p95:float}|null}>,facets:array{type:array<string,int>,level:array<string,int>,channel:array<string,int>,file:array<string,int>},patterns:list<array{pattern:string,count:int,sample:string,level:string}>,profiles:array{count:int,failed:int,p50:float,p95:float,sql:int},interval:int,from:int,to:int,total:int,matched:int,truncated:bool,sampled:float|null,warmable:bool,tookMs:float}
     */
    public function histogram(array $sources, string $logql, int $fromMs, int $toMs, ?Selection $selection = null, int $top = 20, ?Timings $timings = null): array
    {
        $started = microtime(true);
        $timings ??= new Timings();
        $evaluator = $timings->measure('parse', fn (): Evaluator => new Evaluator($this->parser->parse($logql)));
        $selection ??= new Selection();
        $interval = $this->bucketWidth($fromMs, $toMs);
        $deadline = $started + $this->budgetSeconds;
        $tally = new HistogramTally($interval);
        $sampled = null;
        $warmable = false;

        $scanStarted = microtime(true);
        if ('' === trim($logql) && !$selection->needsText()) {
            [$truncated, $sampled, $warmable] = $this->histogramByFile($tally, $sources, $fromMs, $toMs, $selection, $timings, $deadline);
        } else {
            $truncated = $this->histogramByRow($tally, $evaluator, $sources, $fromMs, $toMs, $selection, $timings, $deadline);
        }
        $this->closeScan($timings, $scanStarted);

        $aggregateStarted = microtime(true);
        $result = $tally->result($top);
        $timings->add('aggregate', (microtime(true) - $aggregateStarted) * 1000);

        return $result + [
            'interval' => $interval,
            'from' => $fromMs,
            'to' => $toMs,
            'truncated' => $truncated || $timings->wasBudgetHit(),
            'sampled' => $sampled,
            'warmable' => $warmable,
            'tookMs' => round((microtime(true) - $started) * 1000, 1),
        ];
    }

    /**
     * Brings the index of every file the window touches up to the end of the file. Meant to run
     * once the response has been sent, when {@see histogram()} had to sample what its index did
     * not cover yet (a first build, or a backlog of appended lines): the next load is exact.
     *
     * Each index is locked while it is caught up — a request already busy with one is left alone —
     * and progress is saved as it goes, so stopping at $maxSeconds loses nothing: the next warm-up
     * continues from there.
     *
     * @param array<string,string> $sources
     *
     * @return int files whose index now reaches their end
     */
    public function warmHistogram(array $sources, int $fromMs, int $toMs, ?Selection $selection = null, float $maxSeconds = 120.0): int
    {
        if (null === $this->indexes) {
            return 0;
        }

        $selection ??= new Selection();
        $deadline = microtime(true) + $maxSeconds;
        $complete = 0;

        foreach ($this->merged->sourcesSince($sources, $fromMs) as $path) {
            if (microtime(true) > $deadline) {
                break;
            }
            $index = $this->indexes->open($path, $selection->ownPaths(), self::PATTERN_EVERY);
            if (!$index->lock()) {
                continue;
            }
            try {
                if ($index->catchUp($this->merged, $selection->ownPaths(), self::PATTERN_EVERY, $deadline)) {
                    ++$complete;
                }
            } finally {
                $index->unlock();
            }
        }

        return $complete;
    }

    /**
     * Counts one file into $tally from its line headers.
     *
     * @return bool whether the deadline cut the count short
     */
    private function tallyFile(HistogramTally $tally, string $ref, string $path, int $fromMs, int $toMs, float $fraction, Selection $selection, float $deadline, ?callable $report = null, int $minOffset = 0): bool
    {
        $seen = 0;
        foreach ($this->merged->scanRange($path, $fromMs, $toMs, $fraction, self::PATTERN_EVERY, $selection->excluding(), $report, $minOffset) as $tick) {
            if (0 === ++$seen % self::BUDGET_CHECK_EVERY && microtime(true) > $deadline) {
                return true;
            }
            $level = '' !== $tick['level'] ? $tick['level'] : 'other';
            $tally->facet($tick['type'], $level, $tick['channel'], $ref);
            if (!$selection->matchesFacetValues($tick['type'], $level, $tick['channel'])) {
                continue;
            }
            if (null !== $tick['message']) {
                $tally->pattern($tick['message'], $level, $tick['weight']);
            }
            $tally->record($tick['ts'], $level, $tick['profile']);
        }

        return false;
    }

    /**
     * The full path: every record parsed, merged across files, matched against the query.
     *
     * @param array<string,string> $sources
     *
     * @return bool whether the budget cut the count short
     */
    private function histogramByRow(HistogramTally $tally, Evaluator $evaluator, array $sources, int $fromMs, int $toMs, Selection $selection, Timings $timings, float $deadline): bool
    {
        $seen = 0;
        foreach ($evaluator->filter($this->read($sources, $fromMs, $toMs, $timings, $deadline)) as $row) {
            if (0 === ++$seen % self::BUDGET_CHECK_EVERY && microtime(true) > $deadline) {
                // Counting stops here; the chart shows what was measured and says so.
                return true;
            }

            if (!$selection->matchesSearch($row)) {
                continue;
            }

            $level = '' !== $row['level'] ? strtolower($row['level']) : 'other';
            $tally->facet($row['type'], $level, $row['channel'], $row['file']);

            if (!$selection->matchesFacets($row)) {
                continue;
            }

            // Counted on everything the facets kept, before the pattern narrows — same reason the
            // facet counts are taken before the facet selection: picking one must not erase the rest.
            $tally->pattern($row['message'], $level);
            if (!$selection->matchesPattern($row)) {
                continue;
            }

            $tally->record($row['ts'], $level, $row['profile']);
        }

        return false;
    }

    /**
     * The fast path: each file counted on its own from line headers, newest first.
     *
     * With an index store (and bars of a minute or more), each file's {@see MinuteIndex} is first
     * caught up — only the bytes appended since the last load are read — then answers the window.
     * Whatever the index does not cover yet (a first build cut by the budget) is read directly,
     * sampled when it cannot all be read in time, and left for the warm-up after the response.
     *
     * Without one, files are read directly, sampled the same way when needed.
     *
     * @param array<string,string> $sources
     *
     * @return array{bool,float|null,bool} [truncated, smallest share read (null when everything
     *                                     was), whether a warm-up would make the next load exact]
     */
    private function histogramByFile(HistogramTally $tally, array $sources, int $fromMs, int $toMs, Selection $selection, Timings $timings, float $deadline): array
    {
        $open = $this->merged->sourcesSince($sources, $fromMs);
        $timings->files(\count($sources), \count($open));

        // Newest first: if the budget runs out, what is missing is the oldest part of the window.
        $stats = [];
        foreach ($open as $ref => $path) {
            clearstatcache(true, $path);
            $stats[(string) $ref] = ['size' => (int) @filesize($path), 'mtime' => (int) @filemtime($path)];
        }
        uksort($stats, static fn (string|int $a, string|int $b): int => $stats[(string) $b]['mtime'] <=> $stats[(string) $a]['mtime']);

        $indexed = null !== $this->indexes && $tally->interval() >= 60_000;
        $ownPaths = $selection->ownPaths();
        // Catching up gets half of what is left: on a first build the other half samples what the
        // indexes do not cover yet, instead of the newest file eating the whole budget.
        $catchUpDeadline = microtime(true) + max(0.0, $deadline - microtime(true) - self::BUDGET_MARGIN_SECONDS) * self::CATCH_UP_SHARE;

        // 1. Indexes: catch up on what was appended, answer from them, note what is still unread.
        /** @var array<string,int> $unread ref => byte offset from which the file must still be read */
        $unread = [];
        $readBytes = 0;
        $readSeconds = 0.0;
        $warmable = false;
        foreach ($stats as $ref => $stat) {
            $ref = (string) $ref;
            if (!$indexed) {
                $unread[$ref] = 0;
                continue;
            }

            $started = hrtime(true);
            $index = $this->indexes->open($open[$ref], $ownPaths, self::PATTERN_EVERY);
            $before = $index->offset();
            // Past its share of the budget, an index answers as it is: the rest goes to sampling.
            if (microtime(true) < $catchUpDeadline && $index->lock()) {
                try {
                    $index->catchUp($this->merged, $ownPaths, self::PATTERN_EVERY, $catchUpDeadline);
                } finally {
                    $index->unlock();
                }
            }
            $caughtUp = (hrtime(true) - $started) / 1e6;
            $timings->add('index', $caughtUp);
            // Catching up is the same work as reading: its throughput is the best estimate there is.
            $readBytes += $index->offset() - $before;
            $readSeconds += $caughtUp / 1000;
            $timings->fileTime($ref, $caughtUp);

            $started = hrtime(true);
            $index->tallyInto($tally, $fromMs, $toMs, $selection, $ref);
            $timings->add('cache', (hrtime(true) - $started) / 1e6);

            if ($index->offset() >= $stat['size']) {
                $timings->cached();
            } else {
                $unread[$ref] = $index->offset();
                // A line still being written is not worth a warm-up; a backlog is.
                $warmable = $warmable || $stat['size'] - $index->offset() > self::WARM_UP_BYTES;
            }
        }

        // 2. Read what no index covers, sampling when it cannot all be read in time.
        $remaining = 0;
        foreach ($unread as $ref => $offset) {
            $remaining += max(0, $stats[(string) $ref]['size'] - $offset);
        }
        $smallest = 1.0;

        foreach ($unread as $ref => $offset) {
            $ref = (string) $ref;
            $now = microtime(true);
            if ($now > $deadline) {
                $timings->budgetHit();

                return [true, $smallest < 1.0 ? $smallest : null, $warmable];
            }

            // Throughput measured on this request once it has read enough to mean something.
            $rate = $readBytes >= 1_000_000 && $readSeconds > 0 ? $readBytes / $readSeconds : self::ASSUMED_BYTES_PER_SECOND;
            $affordable = $rate * max(0.0, $deadline - $now - self::BUDGET_MARGIN_SECONDS);
            $fraction = $remaining > 0 ? max(self::MIN_SAMPLE, min(1.0, $affordable / $remaining)) : 1.0;
            $smallest = min($smallest, $fraction);

            $partial = new HistogramTally($tally->interval());
            $bytes = 0;
            $covered = 0;
            $report = static function (float $seekMs, int $read, int $rows, int $size, bool $capped = false, int $through = 0) use ($timings, $ref, &$bytes, &$covered): void {
                $bytes = $read;
                $covered = $through;
                $timings->fileRead($ref, $seekMs, $read, $rows, $size);
            };

            $fileStarted = microtime(true);
            $cut = $this->tallyFile($partial, $ref, $open[$ref], $fromMs, $toMs, $fraction, $selection, $deadline, $report, $offset);
            $elapsed = microtime(true) - $fileStarted;
            $timings->fileTime($ref, $elapsed * 1000);
            $readBytes += $bytes;
            $readSeconds += $elapsed;
            $remaining -= max(0, $stats[$ref]['size'] - $offset);

            // Scale by what was really sampled: a read cut by the budget covered fewer blocks than
            // planned, and assuming 1/fraction would undercount everything it did not reach.
            $factor = $fraction < 1.0 || $cut ? ($bytes > 0 ? max(1.0, $covered / $bytes) : 1.0 / $fraction) : 1.0;
            $tally->merge($partial, $factor);

            if ($cut) {
                $timings->budgetHit();

                return [true, $smallest < 1.0 ? $smallest : null, $warmable];
            }
        }

        return [false, $smallest < 1.0 ? $smallest : null, $warmable];
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
    private function read(array $sources, int $fromMs, int $toMs, ?Timings $timings = null, ?float $deadline = null): \Generator
    {
        yield from $this->merged->readRange($sources, $fromMs, $toMs, self::RANGE_BYTES, $timings, $deadline);
    }

    /**
     * Records the read loop as `scan`, minus the `open` phase it contains: opening the files
     * happens lazily inside the first iteration, and the two are worth telling apart.
     */
    private function closeScan(Timings $timings, float $scanStarted): void
    {
        $loopMs = (microtime(true) - $scanStarted) * 1000;
        $timings->add('scan', max(0.0, $loopMs - $timings->get('open')));
    }
}

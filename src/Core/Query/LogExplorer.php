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

    /** Never read less than this share of a file: below it, the bars are mostly noise. */
    private const MIN_SAMPLE = 0.02;

    /** One info/debug record in this many is parsed in full for the patterns panel. */
    private const PATTERN_EVERY = 8;

    /** A file untouched for this long is no longer being written: its count can be cached. */
    private const QUIET_MS = 600_000;

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
        private readonly ?HistogramCache $cache = null,
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
     *    ({@see histogramByFile()}): rotated files come from the cache, and when what is left
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
     * Reads in full, and caches, every file of the window that {@see histogram()} could have
     * answered from the cache but had to sample or skip. Meant to run once the response has been
     * sent: the next load of the same view is then exact, and fast.
     *
     * Only one warm-up runs at a time (a second one returns at once), and it stops after
     * $maxSeconds — whatever it stored by then is kept.
     *
     * @param array<string,string> $sources
     *
     * @return int files stored
     */
    public function warmHistogram(array $sources, int $fromMs, int $toMs, ?Selection $selection = null, float $maxSeconds = 120.0): int
    {
        if (null === $this->cache) {
            return 0;
        }
        $lock = $this->cache->lock();
        if (null === $lock) {
            return 0;
        }

        $selection ??= new Selection();
        $interval = $this->bucketWidth($fromMs, $toMs);
        $deadline = microtime(true) + $maxSeconds;
        $stored = 0;

        try {
            foreach ($this->merged->sourcesSince($sources, $fromMs) as $ref => $path) {
                $ref = (string) $ref;
                if (microtime(true) > $deadline) {
                    break;
                }
                clearstatcache(true, $path);
                $stat = ['size' => (int) @filesize($path), 'mtime' => (int) @filemtime($path)];
                $key = $this->cacheKey($ref, $path, $stat, $fromMs, $toMs, $interval, $selection);
                if (null === $key || null !== $this->cache->get($key)) {
                    continue;
                }

                $tally = new HistogramTally($interval);
                if ($this->tallyFile($tally, $ref, $path, $fromMs, $toMs, 1.0, $selection, $deadline)) {
                    continue; // cut short by the deadline: not a complete count, not stored
                }
                $this->cache->put($key, $tally);
                ++$stored;
            }
        } finally {
            $this->cache->unlock($lock);
        }

        return $stored;
    }

    /**
     * Counts one file into $tally from its line headers.
     *
     * @return bool whether the deadline cut the count short
     */
    private function tallyFile(HistogramTally $tally, string $ref, string $path, int $fromMs, int $toMs, float $fraction, Selection $selection, float $deadline, ?callable $report = null): bool
    {
        $seen = 0;
        foreach ($this->merged->scanRange($path, $fromMs, $toMs, $fraction, self::PATTERN_EVERY, $selection->excluding(), $report) as $tick) {
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
     * 1. Files wholly inside the window that have gone quiet are answered from the cache.
     * 2. The rest are read; when their size will not fit in what remains of the budget at the rate
     *    observed so far, only a share of each is read and its counts are scaled up.
     * 3. A file read in full and wholly inside the window is stored for next time.
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
            $stats[$ref] = ['size' => (int) @filesize($path), 'mtime' => (int) @filemtime($path)];
        }
        uksort($stats, static fn (string|int $a, string|int $b): int => $stats[$b]['mtime'] <=> $stats[$a]['mtime']);

        // 1. Cache.
        $toRead = [];
        $openStarted = hrtime(true);
        foreach ($stats as $ref => $stat) {
            $ref = (string) $ref;
            $key = $this->cacheKey($ref, $open[$ref], $stat, $fromMs, $toMs, $tally->interval(), $selection);
            $cached = null === $key ? null : $this->cache?->get($key);
            if (null !== $cached) {
                $tally->merge($cached);
                $timings->cached();
                continue;
            }
            $toRead[$ref] = $key;
        }
        $timings->add('cache', (hrtime(true) - $openStarted) / 1e6);

        // 2. Read what is left, sampling when it cannot all be read in time.
        $remaining = 0;
        foreach (array_keys($toRead) as $ref) {
            $remaining += $stats[(string) $ref]['size'];
        }
        $readBytes = 0;
        $readSeconds = 0.0;
        $smallest = 1.0;
        // A cacheable file left sampled or unread is what a warm-up after the response can fix.
        $warmable = false;

        foreach ($toRead as $ref => $key) {
            $ref = (string) $ref;
            $now = microtime(true);
            if ($now > $deadline) {
                $timings->budgetHit();

                return [true, $smallest < 1.0 ? $smallest : null, true];
            }

            // Throughput measured on this request once it has read enough to mean something.
            $rate = $readBytes >= 1_000_000 && $readSeconds > 0 ? $readBytes / $readSeconds : self::ASSUMED_BYTES_PER_SECOND;
            $affordable = $rate * max(0.0, $deadline - $now - self::BUDGET_MARGIN_SECONDS);
            $fraction = $remaining > 0 ? max(self::MIN_SAMPLE, min(1.0, $affordable / $remaining)) : 1.0;
            $smallest = min($smallest, $fraction);

            $partial = new HistogramTally($tally->interval());
            $bytes = 0;
            $report = static function (float $seekMs, int $read, int $rows, int $size) use ($timings, $ref, &$bytes): void {
                $bytes = $read;
                $timings->fileRead($ref, $seekMs, $read, $rows, $size);
            };

            $fileStarted = microtime(true);
            $cut = $this->tallyFile($partial, $ref, $open[$ref], $fromMs, $toMs, $fraction, $selection, $deadline, $report);
            $elapsed = microtime(true) - $fileStarted;
            $timings->fileTime($ref, $elapsed * 1000);
            $readBytes += $bytes;
            $readSeconds += $elapsed;
            $remaining -= $stats[$ref]['size'];

            // 3. A complete, exact count of a file wholly inside the window is worth keeping.
            if (!$cut && $fraction >= 1.0 && null !== $key) {
                $this->cache?->put($key, $partial);
            } elseif (null !== $key) {
                $warmable = true;
            }
            $tally->merge($partial, 1.0 / $fraction);

            if ($cut) {
                $timings->budgetHit();

                return [true, $smallest < 1.0 ? $smallest : null, true];
            }
        }

        return [false, $smallest < 1.0 ? $smallest : null, $warmable];
    }

    /**
     * Cache key of one file's tally, or null when the file must be read: no cache, still being
     * written, or reaching outside the window (its count then depends on where the window falls).
     *
     * @param array{size:int,mtime:int} $stat
     */
    private function cacheKey(string $ref, string $path, array $stat, int $fromMs, int $toMs, int $interval, Selection $selection): ?string
    {
        if (null === $this->cache || 0 === $stat['size']) {
            return null;
        }
        $mtimeMs = $stat['mtime'] * 1000;
        if ($mtimeMs > $toMs || $mtimeMs > (int) (microtime(true) * 1000) - self::QUIET_MS) {
            return null;
        }
        $first = $this->merged->firstTimestamp($path);
        if (null === $first || $first < $fromMs) {
            return null;
        }

        return $this->cache->key((string) (realpath($path) ?: $path), $stat['size'], $stat['mtime'], [
            'ref' => $ref,
            'interval' => $interval,
            'patternEvery' => self::PATTERN_EVERY,
        ] + $selection->fingerprint());
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

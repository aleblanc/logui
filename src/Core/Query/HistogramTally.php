<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Core\Query;

/**
 * Everything one histogram counts — bars, facets, patterns, request statistics — in a form that
 * can be fed record by record (a direct read), cell by cell (a {@see MinuteIndex}), and merged
 * with scaling (a sampled read).
 *
 * Bars sit on slots aligned to the clock (`intdiv($ts, $interval) * $interval`), not to the start
 * of the window: a slot then means the same thing whatever window asks for it, which is what lets
 * minutes stored in an index be regrouped into any bar width.
 *
 * Request durations are kept as a histogram ({@see durationBin()}) rather than a list: exact to the
 * millisecond below one second, within 1 % above, and bounded in size however busy the window.
 *
 * @phpstan-type Bins array<int,int>
 * @phpstan-type Bucket array{count:int,levels:array<string,int>,requests:int,bins:Bins}
 * @phpstan-type Facets array{type:array<string,int>,level:array<string,int>,channel:array<string,int>,file:array<string,int>}
 */
final class HistogramTally
{
    /** Below this, a duration bin is the duration rounded to the millisecond. */
    private const EXACT_MS = 1000;

    /** Above {@see EXACT_MS}, bins grow geometrically by this ratio (±1 %). */
    private const BIN_RATIO = 1.02;

    /** @var array<int,Bucket> slot (epoch ms) => bucket */
    private array $buckets = [];

    /** @var Facets */
    private array $facets = ['type' => [], 'level' => [], 'channel' => [], 'file' => []];

    private int $matched = 0;
    private int $total = 0;
    private int $requests = 0;
    private int $failed = 0;
    private int $sql = 0;

    /** @var Bins */
    private array $bins = [];

    private Patterns $patterns;

    public function __construct(private readonly int $interval)
    {
        $this->patterns = new Patterns();
    }

    public function interval(): int
    {
        return $this->interval;
    }

    /** Duration (ms) → bin: the millisecond below one second, a 2 % step above. */
    public static function durationBin(float $ms): int
    {
        if ($ms < self::EXACT_MS) {
            return max(0, (int) round($ms));
        }

        return self::EXACT_MS + (int) floor(log($ms / self::EXACT_MS) / log(self::BIN_RATIO));
    }

    /** Bin → the duration it stands for. */
    public static function binValue(int $bin): float
    {
        if ($bin < self::EXACT_MS) {
            return (float) $bin;
        }

        return round(self::EXACT_MS * self::BIN_RATIO ** ($bin - self::EXACT_MS + 0.5), 1);
    }

    /** A record (or $weight identical ones) the search kept: it counts in the facets, before the facet selection applies. */
    public function facet(string $type, string $level, string $channel, string $file, int $weight = 1): void
    {
        $this->matched += $weight;
        $this->facets['type'][$type] = ($this->facets['type'][$type] ?? 0) + $weight;
        $this->facets['level'][$level] = ($this->facets['level'][$level] ?? 0) + $weight;
        if ('' !== $channel) {
            $this->facets['channel'][$channel] = ($this->facets['channel'][$channel] ?? 0) + $weight;
        }
        $this->facets['file'][$file] = ($this->facets['file'][$file] ?? 0) + $weight;
    }

    public function pattern(string $message, string $level, int $weight = 1): void
    {
        $this->patterns->add($message, $level, $weight);
    }

    /** A pattern already normalised (read back from an index). */
    public function patternKey(string $pattern, string $sample, string $level, int $count): void
    {
        $this->patterns->addKey($pattern, $sample, $level, $count);
    }

    /**
     * A record every filter kept: it counts in the request statistics and, when datable, in its bar.
     *
     * @param array<string,mixed>|null $profile the telemetry header of a request/command row
     */
    public function record(?int $ts, string $level, ?array $profile): void
    {
        $bins = [];
        $failed = 0;
        $sql = 0;
        if (null !== $profile) {
            $bins[self::durationBin((float) ($profile['duration_ms'] ?? 0))] = 1;
            $sql = (int) ($profile['sql'] ?? 0);
            $failed = ($profile['failed'] ?? false) ? 1 : 0;
        }
        $this->cell($ts, true, $level, 1, null !== $profile ? 1 : 0, $failed, $sql, $bins);
    }

    /**
     * $count records that share a minute, a level and their request statistics — one cell of a
     * {@see MinuteIndex}, or one record.
     *
     * @param bool $dated false for undatable lines: they count in the totals, not on a bar
     * @param Bins $bins  request durations
     */
    public function cell(?int $ts, bool $dated, string $level, int $count, int $requests, int $failed, int $sql, array $bins): void
    {
        $this->requests += $requests;
        $this->failed += $failed;
        $this->sql += $sql;
        foreach ($bins as $bin => $n) {
            $this->bins[$bin] = ($this->bins[$bin] ?? 0) + $n;
        }

        if (!$dated || null === $ts) {
            return; // undatable lines cannot be placed on a time axis
        }

        $slot = intdiv($ts, $this->interval) * $this->interval;
        $bucket = &$this->buckets[$slot];
        $bucket ??= ['count' => 0, 'levels' => [], 'requests' => 0, 'bins' => []];
        $bucket['count'] += $count;
        $bucket['levels'][$level] = ($bucket['levels'][$level] ?? 0) + $count;
        $bucket['requests'] += $requests;
        foreach ($bins as $bin => $n) {
            $bucket['bins'][$bin] = ($bucket['bins'][$bin] ?? 0) + $n;
        }
        unset($bucket);
        $this->total += $count;
    }

    /**
     * Folds another tally into this one, its counts multiplied by $factor — a sampled read stands
     * for the whole range that way. Duration bins are kept as they are: percentiles do not scale.
     */
    public function merge(self $other, float $factor = 1.0): void
    {
        $scale = static fn (int $n): int => 1.0 === $factor ? $n : (int) round($n * $factor);

        foreach ($other->buckets as $slot => $b) {
            $bucket = &$this->buckets[$slot];
            $bucket ??= ['count' => 0, 'levels' => [], 'requests' => 0, 'bins' => []];
            $bucket['count'] += $scale($b['count']);
            foreach ($b['levels'] as $level => $n) {
                $bucket['levels'][$level] = ($bucket['levels'][$level] ?? 0) + $scale($n);
            }
            $bucket['requests'] += $scale($b['requests']);
            foreach ($b['bins'] as $bin => $n) {
                $bucket['bins'][$bin] = ($bucket['bins'][$bin] ?? 0) + $n;
            }
            unset($bucket);
        }
        foreach ($other->facets as $field => $counts) {
            foreach ($counts as $value => $n) {
                $this->facets[$field][$value] = ($this->facets[$field][$value] ?? 0) + $scale($n);
            }
        }
        foreach ($other->bins as $bin => $n) {
            $this->bins[$bin] = ($this->bins[$bin] ?? 0) + $n;
        }
        $this->matched += $scale($other->matched);
        $this->total += $scale($other->total);
        $this->requests += $scale($other->requests);
        $this->failed += $scale($other->failed);
        $this->sql += $scale($other->sql);
        $this->patterns->merge($other->patterns, $factor);
    }

    /**
     * Nearest-rank percentile of a duration histogram; 0 when empty.
     *
     * @param Bins $bins
     */
    public static function percentile(array $bins, float $p): float
    {
        $n = array_sum($bins);
        if (0 === $n) {
            return 0.0;
        }

        ksort($bins);
        $rank = max(1, (int) ceil($p * $n));
        $seen = 0;
        foreach ($bins as $bin => $count) {
            $seen += $count;
            if ($seen >= $rank) {
                return self::binValue($bin);
            }
        }

        return self::binValue((int) array_key_last($bins));
    }

    /**
     * The histogram as the endpoint answers it (without the window and timing fields).
     *
     * @return array{buckets:list<array{ts:int,count:int,levels:array<string,int>,requests:int,latency:array{n:int,p50:float,p95:float}|null}>,facets:Facets,patterns:list<array{pattern:string,count:int,sample:string,level:string}>,profiles:array{count:int,failed:int,p50:float,p95:float,sql:int},total:int,matched:int}
     */
    public function result(int $top): array
    {
        $buckets = [];
        ksort($this->buckets);
        foreach ($this->buckets as $slot => $b) {
            $latency = null;
            if ([] !== $b['bins']) {
                $latency = [
                    'n' => array_sum($b['bins']),
                    'p50' => self::percentile($b['bins'], 0.5),
                    'p95' => self::percentile($b['bins'], 0.95),
                ];
            }
            $buckets[] = ['ts' => $slot, 'count' => $b['count'], 'levels' => $b['levels'], 'requests' => $b['requests'], 'latency' => $latency];
        }

        $facets = $this->facets;
        foreach ($facets as &$counts) {
            arsort($counts);
        }
        unset($counts);

        return [
            'buckets' => $buckets,
            'facets' => $facets,
            'patterns' => $this->patterns->top($top),
            'profiles' => [
                'count' => $this->requests,
                'failed' => $this->failed,
                'p50' => self::percentile($this->bins, 0.5),
                'p95' => self::percentile($this->bins, 0.95),
                'sql' => $this->sql,
            ],
            'total' => $this->total,
            'matched' => $this->matched,
        ];
    }
}

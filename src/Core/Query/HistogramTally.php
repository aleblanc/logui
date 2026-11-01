<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Core\Query;

/**
 * Everything one histogram counts — bars, facets, patterns, request statistics — for some set of
 * records, in a form that can be merged, scaled and stored.
 *
 * That is what lets a histogram be built file by file: a rotated file's tally is computed once and
 * cached ({@see HistogramCache}), a sampled file's tally is scaled up to stand for the whole file,
 * and the tallies of every file are merged into the answer.
 *
 * Bars sit on slots aligned to the clock (`intdiv($ts, $interval) * $interval`), not to the start
 * of the window: a slot then means the same thing whatever window asks for it, which is what makes
 * a cached tally reusable from one page load to the next.
 *
 * @phpstan-type Bucket array{count:int,levels:array<string,int>,requests:int,durations:list<float>}
 * @phpstan-type Facets array{type:array<string,int>,level:array<string,int>,channel:array<string,int>,file:array<string,int>}
 */
final class HistogramTally
{
    /** @var array<int,Bucket> slot (epoch ms) => bucket */
    private array $buckets = [];

    /** @var Facets */
    private array $facets = ['type' => [], 'level' => [], 'channel' => [], 'file' => []];

    private int $matched = 0;
    private int $total = 0;
    private int $requests = 0;
    private int $failed = 0;
    private int $sql = 0;

    /** @var list<float> */
    private array $durations = [];

    private Patterns $patterns;

    public function __construct(private readonly int $interval)
    {
        $this->patterns = new Patterns();
    }

    public function interval(): int
    {
        return $this->interval;
    }

    /** A record the search kept: it counts in the facets, before the facet selection applies. */
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

    /**
     * A record every filter kept: it counts in the request statistics and, when datable, in its bar.
     *
     * @param array<string,mixed>|null $profile the telemetry header of a request/command row
     */
    public function record(?int $ts, string $level, ?array $profile): void
    {
        $duration = null;
        if (null !== $profile) {
            $duration = (float) ($profile['duration_ms'] ?? 0);
            $this->durations[] = $duration;
            ++$this->requests;
            $this->sql += (int) ($profile['sql'] ?? 0);
            if ($profile['failed'] ?? false) {
                ++$this->failed;
            }
        }

        if (null === $ts) {
            return; // undatable lines cannot be placed on a time axis
        }

        $slot = intdiv($ts, $this->interval) * $this->interval;
        $bucket = &$this->buckets[$slot];
        $bucket ??= ['count' => 0, 'levels' => [], 'requests' => 0, 'durations' => []];
        ++$bucket['count'];
        $bucket['levels'][$level] = ($bucket['levels'][$level] ?? 0) + 1;
        if (null !== $duration) {
            ++$bucket['requests'];
            $bucket['durations'][] = $duration;
        }
        unset($bucket);
        ++$this->total;
    }

    /**
     * Folds another tally into this one, its counts multiplied by $factor — a sampled file's tally
     * stands for the whole file that way. Durations are kept as they are: they feed percentiles,
     * which a sample estimates without scaling.
     */
    public function merge(self $other, float $factor = 1.0): void
    {
        $scale = static fn (int $n): int => 1.0 === $factor ? $n : (int) round($n * $factor);

        foreach ($other->buckets as $slot => $b) {
            $bucket = &$this->buckets[$slot];
            $bucket ??= ['count' => 0, 'levels' => [], 'requests' => 0, 'durations' => []];
            $bucket['count'] += $scale($b['count']);
            foreach ($b['levels'] as $level => $n) {
                $bucket['levels'][$level] = ($bucket['levels'][$level] ?? 0) + $scale($n);
            }
            $bucket['requests'] += $scale($b['requests']);
            array_push($bucket['durations'], ...$b['durations']);
            unset($bucket);
        }
        foreach ($other->facets as $field => $counts) {
            foreach ($counts as $value => $n) {
                $this->facets[$field][$value] = ($this->facets[$field][$value] ?? 0) + $scale($n);
            }
        }
        $this->matched += $scale($other->matched);
        $this->total += $scale($other->total);
        $this->requests += $scale($other->requests);
        $this->failed += $scale($other->failed);
        $this->sql += $scale($other->sql);
        array_push($this->durations, ...$other->durations);
        $this->patterns->merge($other->patterns, $factor);
    }

    /**
     * Plain data for the cache.
     *
     * @return array<string,mixed>
     */
    public function export(): array
    {
        return [
            'interval' => $this->interval,
            'buckets' => $this->buckets,
            'facets' => $this->facets,
            'matched' => $this->matched,
            'total' => $this->total,
            'requests' => $this->requests,
            'failed' => $this->failed,
            'sql' => $this->sql,
            'durations' => $this->durations,
            'patterns' => $this->patterns->export(),
        ];
    }

    /**
     * The reverse of {@see export()}; null when the data is not a tally (a corrupt cache entry).
     *
     * @param array<array-key,mixed> $data
     */
    public static function import(array $data): ?self
    {
        foreach (['interval', 'matched', 'total', 'requests', 'failed', 'sql'] as $key) {
            if (!\is_int($data[$key] ?? null)) {
                return null;
            }
        }
        foreach (['buckets', 'facets', 'durations', 'patterns'] as $key) {
            if (!\is_array($data[$key] ?? null)) {
                return null;
            }
        }
        /** @var int $interval */
        $interval = $data['interval'];
        if ($interval <= 0) {
            return null;
        }

        $tally = new self($interval);
        /** @var array<int,Bucket> $buckets */
        $buckets = $data['buckets'];
        $tally->buckets = $buckets;
        /** @var Facets $facets */
        $facets = $data['facets'] + ['type' => [], 'level' => [], 'channel' => [], 'file' => []];
        $tally->facets = $facets;
        /** @var list<float> $durations */
        $durations = array_map(floatval(...), array_values($data['durations']));
        $tally->durations = $durations;
        /** @var array<string,array{count:int,sample:string,level:string,rank:int}> $patterns */
        $patterns = $data['patterns'];
        $tally->patterns = Patterns::import($patterns);
        /** @var int $matched */
        $matched = $data['matched'];
        /** @var int $total */
        $total = $data['total'];
        /** @var int $requests */
        $requests = $data['requests'];
        /** @var int $failed */
        $failed = $data['failed'];
        /** @var int $sql */
        $sql = $data['sql'];
        [$tally->matched, $tally->total, $tally->requests, $tally->failed, $tally->sql] = [$matched, $total, $requests, $failed, $sql];

        return $tally;
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
            if ([] !== $b['durations']) {
                $durations = $b['durations'];
                sort($durations);
                $latency = [
                    'n' => \count($durations),
                    'p50' => LogExplorer::percentile($durations, 0.5),
                    'p95' => LogExplorer::percentile($durations, 0.95),
                ];
            }
            $buckets[] = ['ts' => $slot, 'count' => $b['count'], 'levels' => $b['levels'], 'requests' => $b['requests'], 'latency' => $latency];
        }

        $facets = $this->facets;
        foreach ($facets as &$counts) {
            arsort($counts);
        }
        unset($counts);

        $durations = $this->durations;
        sort($durations);

        return [
            'buckets' => $buckets,
            'facets' => $facets,
            'patterns' => $this->patterns->top($top),
            'profiles' => [
                'count' => $this->requests,
                'failed' => $this->failed,
                'p50' => LogExplorer::percentile($durations, 0.5),
                'p95' => LogExplorer::percentile($durations, 0.95),
                'sql' => $this->sql,
            ],
            'total' => $this->total,
            'matched' => $this->matched,
        ];
    }
}

<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Tests\Core\Query;

use Aleblanc\LogUi\Core\Query\HistogramCache;
use Aleblanc\LogUi\Core\Query\HistogramTally;
use Aleblanc\LogUi\Core\Query\LogExplorer;
use Aleblanc\LogUi\Core\Query\LogQL\Parser;
use Aleblanc\LogUi\Core\Query\Selection;
use Aleblanc\LogUi\Core\Stats\Timings;
use Aleblanc\LogUi\Core\Storage\MergedLogReader;
use Aleblanc\LogUi\Core\Storage\PlainLogReader;
use Aleblanc\LogUi\Core\Time\TimestampParser;
use PHPUnit\Framework\TestCase;

/**
 * The header-only histogram (no text search) must count what the full parse counts, and its
 * cache, sampling and warm-up must stay faithful to it.
 */
final class HistogramFastPathTest extends TestCase
{
    /** Two hours ago, on a whole hour — the fixture's first record. */
    private int $base;

    private string $dir;

    protected function setUp(): void
    {
        $this->base = (intdiv(time(), 3600) - 3) * 3600;
        $this->dir = sys_get_temp_dir().'/logui-fast-'.uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/{,.}*', \GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        foreach (glob($this->dir.'/cache/{,.}*', \GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        @rmdir($this->dir.'/cache');
        @rmdir($this->dir);
    }

    /**
     * A Monolog file over two hours: info noise, warnings, errors with a stack trace, a JSON event
     * and a raw line — every kind of line the fast path has to tell apart.
     */
    private function makeLog(string $name, int $records, int $quietForSeconds = 3600): string
    {
        $lines = [];
        for ($i = 0; $i < $records; ++$i) {
            $at = $this->base + intdiv($i * 7200, $records);
            $date = gmdate('Y-m-d\TH:i:s', $at).sprintf('.%06d', $i).'+00:00';
            $lines[] = match (true) {
                0 === $i % 50 => "[$date] app.ERROR: Payment $i failed {\"order\":$i} []\n#0 /var/www/src/Pay.php(12): charge()\n#1 {main}",
                0 === $i % 10 => "[$date] security.WARNING: Login throttled for user $i [] []",
                0 === $i % 97 => sprintf('{"ts":%d,"source":"eth0","rx":%d}', $at, $i),
                default => "[$date] app.INFO: Ad $i viewed {\"ad\":$i} {\"logui_id\":\"abc$i\"}",
            };
        }
        $path = $this->dir.'/'.$name;
        file_put_contents($path, implode("\n", $lines)."\n");
        touch($path, time() - $quietForSeconds);

        return $path;
    }

    private function explorer(?HistogramCache $cache = null, float $budget = 30.0): LogExplorer
    {
        return new LogExplorer(new MergedLogReader(new PlainLogReader(new TimestampParser('UTC'))), new Parser(), $budget, $cache);
    }

    /** @return array<string,mixed> */
    private function comparable(array $histogram): array
    {
        unset($histogram['tookMs'], $histogram['sampled'], $histogram['warmable']);

        return $histogram;
    }

    public function test_the_fast_path_counts_exactly_what_the_full_parse_counts(): void
    {
        // Under 2 000 info records, so patterns are counted exactly too (sampling starts past that).
        $sources = ['app.log' => $this->makeLog('app.log', 2000)];
        $from = ($this->base - 600) * 1000;
        $to = ($this->base + 7800) * 1000;

        $fast = $this->explorer()->histogram($sources, '', $from, $to);
        // A line filter that keeps everything forces the full parse.
        $full = $this->explorer()->histogram($sources, '|= ""', $from, $to);

        self::assertNull($fast['sampled']);
        self::assertSame(2000, $full['matched']);
        self::assertSame($this->comparable($full), $this->comparable($fast));
    }

    public function test_bars_are_aligned_to_the_clock(): void
    {
        $sources = ['app.log' => $this->makeLog('app.log', 200)];
        $from = ($this->base - 17) * 1000; // deliberately not on a bucket boundary

        $h = $this->explorer()->histogram($sources, '', $from, $from + 3_600_000);

        foreach ($h['buckets'] as $bucket) {
            self::assertSame(0, $bucket['ts'] % $h['interval']);
        }
    }

    public function test_a_quiet_file_inside_the_window_is_counted_once_then_read_from_the_cache(): void
    {
        $path = $this->makeLog('old.log', 1000);
        touch($path, $this->base + 7201); // last written well over ten minutes ago
        $sources = ['old.log' => $path];
        $cache = new HistogramCache($this->dir.'/cache');
        $from = ($this->base - 600) * 1000;
        $to = time() * 1000;

        $first = new Timings();
        $miss = $this->explorer($cache)->histogram($sources, '', $from, $to, null, 20, $first);
        $second = new Timings();
        $hit = $this->explorer($cache)->histogram($sources, '', $from, $to, null, 20, $second);

        self::assertSame(0, $first->toArray()['steps']['files_cached']);
        self::assertSame(1, $second->toArray()['steps']['files_cached']);
        self::assertSame($this->comparable($miss), $this->comparable($hit));
    }

    public function test_a_file_reaching_outside_the_window_is_not_cached(): void
    {
        $path = $this->makeLog('old.log', 1000);
        touch($path, $this->base + 7201);
        $cache = new HistogramCache($this->dir.'/cache');
        // The window starts in the middle of the file: its count depends on where the window falls.
        $from = ($this->base + 3600) * 1000;

        $this->explorer($cache)->histogram(['old.log' => $path], '', $from, time() * 1000);

        self::assertSame([], glob($this->dir.'/cache/*.json'));
    }

    public function test_what_cannot_be_read_in_time_is_sampled_and_extrapolated(): void
    {
        $path = $this->makeLog('big.log', 40_000);
        $sources = ['big.log' => $path];
        $from = ($this->base - 600) * 1000;
        $to = ($this->base + 7800) * 1000;

        // 0.6 s of budget, 0.5 s of it kept back: the assumed 8 MB/s affords ~0.8 MB of a ~3 MB file.
        $h = $this->explorer(null, 0.6)->histogram($sources, '', $from, $to);

        self::assertNotNull($h['sampled']);
        self::assertLessThan(1.0, $h['sampled']);
        // An estimate, not a count — but in the right ballpark.
        self::assertEqualsWithDelta(40_000, $h['matched'], 40_000 * 0.25);
    }

    public function test_warming_up_stores_what_the_page_could_only_sample(): void
    {
        $path = $this->makeLog('old.log', 1000);
        touch($path, $this->base + 7201);
        $sources = ['old.log' => $path];
        $cache = new HistogramCache($this->dir.'/cache');
        $from = ($this->base - 600) * 1000;
        $to = time() * 1000;

        self::assertSame(1, $this->explorer($cache)->warmHistogram($sources, $from, $to, new Selection()));
        self::assertSame(0, $this->explorer($cache)->warmHistogram($sources, $from, $to, new Selection()), 'already cached');

        $timings = new Timings();
        $this->explorer($cache)->histogram($sources, '', $from, $to, new Selection(), 20, $timings);
        self::assertSame(1, $timings->toArray()['steps']['files_cached']);
    }

    public function test_a_corrupt_cache_entry_is_ignored(): void
    {
        self::assertNull(HistogramTally::import(['interval' => 'x']));
        self::assertNull(HistogramTally::import([]));
    }
}

<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Tests\Core\Query;

use Aleblanc\LogUi\Core\Query\LogExplorer;
use Aleblanc\LogUi\Core\Query\LogQL\Parser;
use Aleblanc\LogUi\Core\Query\MinuteIndexStore;
use Aleblanc\LogUi\Core\Query\Selection;
use Aleblanc\LogUi\Core\Stats\Timings;
use Aleblanc\LogUi\Core\Storage\MergedLogReader;
use Aleblanc\LogUi\Core\Storage\PlainLogReader;
use Aleblanc\LogUi\Core\Time\TimestampParser;
use PHPUnit\Framework\TestCase;

/**
 * The header-only histogram (no text search) must count what the full parse counts — read
 * directly, sampled, or from its incremental per-minute index, however the file changed since.
 */
final class HistogramFastPathTest extends TestCase
{
    /** Three hours ago, on a whole hour — the fixture's first record. */
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
        $this->remove($this->dir);
    }

    private function remove(string $dir): void
    {
        foreach (glob($dir.'/{,.}*', \GLOB_BRACE) ?: [] as $entry) {
            if (\in_array(basename($entry), ['.', '..'], true)) {
                continue;
            }
            is_dir($entry) ? $this->remove($entry) : unlink($entry);
        }
        @rmdir($dir);
    }

    /**
     * Records $from..$to-1 of a two-hour Monolog file: info noise, warnings, errors with a stack
     * trace, JSON events, and requests to LogUI's own pages.
     */
    private function lines(int $from, int $to, int $of): string
    {
        $lines = [];
        for ($i = $from; $i < $to; ++$i) {
            $at = $this->base + intdiv($i * 7200, $of);
            $date = gmdate('Y-m-d\TH:i:s', $at).sprintf('.%06d', $i).'+00:00';
            $lines[] = match (true) {
                0 === $i % 50 => "[$date] app.ERROR: Payment $i failed {\"order\":$i} []\n#0 /var/www/src/Pay.php(12): charge()\n#1 {main}",
                0 === $i % 10 => "[$date] security.WARNING: Login throttled for user $i [] []",
                0 === $i % 97 => sprintf('{"ts":%d,"source":"eth0","rx":%d}', $at, $i),
                0 === $i % 13 => "[$date] request.INFO: Matched route \"logui_explore\". {\"request_uri\":\"http://localhost/_logui/explore/query\"} []",
                default => "[$date] app.INFO: Ad $i viewed {\"ad\":$i} {\"logui_id\":\"abc$i\"}",
            };
        }

        return implode("\n", $lines)."\n";
    }

    private function makeLog(string $name, int $records): string
    {
        $path = $this->dir.'/'.$name;
        file_put_contents($path, $this->lines(0, $records, $records));

        return $path;
    }

    private function explorer(bool $indexed = false, float $budget = 30.0): LogExplorer
    {
        return new LogExplorer(
            new MergedLogReader(new PlainLogReader(new TimestampParser('UTC'))),
            new Parser(),
            $budget,
            $indexed ? new MinuteIndexStore($this->dir.'/index') : null,
        );
    }

    /** @return array<string,mixed> */
    private function comparable(array $histogram): array
    {
        unset($histogram['tookMs'], $histogram['sampled'], $histogram['warmable']);

        return $histogram;
    }

    /** The full parse of the same question — the reference every fast answer must equal. */
    private function reference(array $sources, int $from, int $to, ?Selection $selection = null): array
    {
        // A line filter that keeps everything forces the full parse.
        return $this->comparable($this->explorer()->histogram($sources, '|= ""', $from, $to, $selection));
    }

    /** @return array{int,int} */
    private function window(): array
    {
        return [($this->base - 600) * 1000, ($this->base + 7800) * 1000];
    }

    public function test_the_direct_fast_path_counts_exactly_what_the_full_parse_counts(): void
    {
        // Under 2 000 info records, so patterns are counted exactly too (sampling starts past that).
        $sources = ['app.log' => $this->makeLog('app.log', 2000)];
        [$from, $to] = $this->window();

        $fast = $this->explorer()->histogram($sources, '', $from, $to);

        self::assertNull($fast['sampled']);
        self::assertSame(2000, $fast['matched']);
        self::assertSame($this->reference($sources, $from, $to), $this->comparable($fast));
    }

    public function test_the_index_answers_exactly_what_the_full_parse_answers(): void
    {
        $sources = ['app.log' => $this->makeLog('app.log', 2000)];
        [$from, $to] = $this->window();

        $timings = new Timings();
        $indexed = $this->explorer(true)->histogram($sources, '', $from, $to, null, 20, $timings);

        self::assertSame(1, $timings->toArray()['steps']['files_cached'], 'the index reaches the end of the file');
        self::assertFalse($indexed['warmable']);
        self::assertSame($this->reference($sources, $from, $to), $this->comparable($indexed));
    }

    public function test_appended_lines_are_read_incrementally(): void
    {
        $path = $this->dir.'/app.log';
        file_put_contents($path, $this->lines(0, 1000, 2000));
        $sources = ['app.log' => $path];
        [$from, $to] = $this->window();

        $this->explorer(true)->histogram($sources, '', $from, $to);
        file_put_contents($path, $this->lines(1000, 2000, 2000), \FILE_APPEND);
        $timings = new Timings();
        $second = $this->explorer(true)->histogram($sources, '', $from, $to, null, 20, $timings);

        self::assertSame($this->reference($sources, $from, $to), $this->comparable($second));
        self::assertSame(1, $timings->toArray()['steps']['files_cached']);
    }

    public function test_a_line_still_being_written_is_shown_once_and_indexed_when_complete(): void
    {
        $path = $this->dir.'/app.log';
        file_put_contents($path, $this->lines(0, 100, 100).'['.gmdate('Y-m-d\TH:i:s', $this->base + 60).'+00:00] app.INFO: half');
        $sources = ['app.log' => $path];
        [$from, $to] = $this->window();

        $first = $this->explorer(true)->histogram($sources, '', $from, $to);
        file_put_contents($path, " written\n", \FILE_APPEND);
        $second = $this->explorer(true)->histogram($sources, '', $from, $to);

        // The index stops before the unfinished line; the direct read of what it does not cover
        // still shows it — and once complete it is indexed, never counted twice.
        self::assertSame(101, $first['matched']);
        self::assertFalse($first['warmable'], 'a few unindexed bytes are not worth a warm-up');
        self::assertSame(101, $second['matched']);
    }

    public function test_a_truncated_and_regrown_file_is_reindexed(): void
    {
        $path = $this->dir.'/app.log';
        file_put_contents($path, $this->lines(0, 500, 2000));
        $sources = ['app.log' => $path];
        [$from, $to] = $this->window();
        $this->explorer(true)->histogram($sources, '', $from, $to);

        // copytruncate, then more than the old size written before the next load: same inode,
        // bigger file — only the bytes before the old offset tell it apart.
        $handle = fopen($path, 'r+');
        ftruncate($handle, 0);
        fclose($handle);
        file_put_contents($path, $this->lines(1000, 2000, 2000));
        $after = $this->explorer(true)->histogram($sources, '', $from, $to);

        self::assertSame($this->reference($sources, $from, $to), $this->comparable($after));
    }

    public function test_a_rotated_file_is_reindexed(): void
    {
        $path = $this->dir.'/app.log';
        file_put_contents($path, $this->lines(0, 1000, 2000));
        $sources = ['app.log' => $path];
        [$from, $to] = $this->window();
        $this->explorer(true)->histogram($sources, '', $from, $to);

        rename($path, $path.'.1');
        file_put_contents($path, $this->lines(1000, 1200, 2000));
        $after = $this->explorer(true)->histogram($sources, '', $from, $to);

        self::assertSame($this->reference($sources, $from, $to), $this->comparable($after));
    }

    public function test_own_traffic_is_hidden_or_shown_from_the_same_index(): void
    {
        $sources = ['app.log' => $this->makeLog('app.log', 1500)];
        [$from, $to] = $this->window();
        $hidden = new Selection([], [], null, ['/_logui'], [], null, ['/_logui']);
        $shown = new Selection([], [], null, [], [], null, ['/_logui']);

        $withoutSelf = $this->explorer(true)->histogram($sources, '', $from, $to, $hidden);
        $withSelf = $this->explorer(true)->histogram($sources, '', $from, $to, $shown);

        self::assertLessThan($withSelf['matched'], $withoutSelf['matched']);
        self::assertSame($this->reference($sources, $from, $to, $hidden)['buckets'], $withoutSelf['buckets']);
        self::assertSame($this->reference($sources, $from, $to, $shown)['buckets'], $withSelf['buckets']);
    }

    public function test_facet_filters_are_answered_from_the_index(): void
    {
        $sources = ['app.log' => $this->makeLog('app.log', 1500)];
        [$from, $to] = $this->window();
        $errors = new Selection(['error']);

        $h = $this->explorer(true)->histogram($sources, '', $from, $to, $errors);
        $reference = $this->reference($sources, $from, $to, $errors);

        self::assertSame($reference['buckets'], $h['buckets']);
        self::assertSame($reference['facets'], $h['facets'], 'facets still list every level');
    }

    public function test_bars_are_aligned_to_the_clock(): void
    {
        $sources = ['app.log' => $this->makeLog('app.log', 200)];
        $from = ($this->base - 17) * 1000; // deliberately not on a bucket boundary

        $h = $this->explorer(true)->histogram($sources, '', $from, $from + 3_600_000);

        foreach ($h['buckets'] as $bucket) {
            self::assertSame(0, $bucket['ts'] % $h['interval']);
        }
    }

    public function test_what_cannot_be_read_in_time_is_sampled_and_extrapolated(): void
    {
        $sources = ['big.log' => $this->makeLog('big.log', 40_000)];
        [$from, $to] = $this->window();

        // 0.6 s of budget, 0.5 s of it kept back: the assumed 8 MB/s affords ~0.8 MB of a ~3 MB file.
        $h = $this->explorer(false, 0.6)->histogram($sources, '', $from, $to);

        self::assertNotNull($h['sampled']);
        self::assertLessThan(1.0, $h['sampled']);
        // An estimate, not a count — but in the right ballpark.
        self::assertEqualsWithDelta(40_000, $h['matched'], 40_000 * 0.25);
    }

    public function test_a_first_build_cut_by_the_budget_is_sampled_then_finished_by_the_warm_up(): void
    {
        $sources = ['big.log' => $this->makeLog('big.log', 40_000)];
        [$from, $to] = $this->window();

        // 1.5 s, 0.5 s kept back: half of the rest builds the index, half samples what it misses.
        $first = $this->explorer(true, 1.5)->histogram($sources, '', $from, $to);
        self::assertTrue($first['warmable']);
        self::assertEqualsWithDelta(40_000, $first['matched'], 40_000 * 0.25);

        self::assertSame(1, $this->explorer(true)->warmHistogram($sources, $from, $to, new Selection()));

        $timings = new Timings();
        $warm = $this->explorer(true, 0.6)->histogram($sources, '', $from, $to, null, 20, $timings);
        self::assertNull($warm['sampled']);
        self::assertFalse($warm['warmable']);
        self::assertSame(40_000, $warm['matched']);
        self::assertSame(1, $timings->toArray()['steps']['files_cached']);
    }
}

<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Tests\Core\Query;

use Aleblanc\LogUi\Core\Query\LogExplorer;
use Aleblanc\LogUi\Core\Storage\MergedLogReader;
use Aleblanc\LogUi\Core\Storage\PlainLogReader;
use Aleblanc\LogUi\Core\Time\TimestampParser;
use PHPUnit\Framework\TestCase;

final class LogExplorerTest extends TestCase
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

    /**
     * @param list<array{int,string,string}> $records [second offset, level, message]
     */
    private function makeFile(string $name, array $records): string
    {
        $path = tempnam(sys_get_temp_dir(), 'logui_explorer_').'_'.$name.'.log';
        $this->files[] = $path;

        $base = strtotime(self::BASE.' UTC');
        $lines = [];
        foreach ($records as [$second, $level, $message]) {
            $lines[] = sprintf(
                '[%s] app.%s: %s',
                gmdate('Y-m-d\TH:i:sP', $base + $second),
                strtoupper($level),
                $message,
            );
        }
        file_put_contents($path, implode("\n", $lines)."\n");

        return $path;
    }

    /** filesize() can fail; a test must not silently carry a false through. */
    private function sizeOf(string $path): int
    {
        $size = filesize($path);
        if (false === $size) {
            self::fail('unable to stat the fixture');
        }

        return $size;
    }

    private function explorer(): LogExplorer
    {
        return new LogExplorer(new MergedLogReader(new PlainLogReader(new TimestampParser('UTC'))));
    }

    private function at(int $second): int
    {
        return (strtotime(self::BASE.' UTC') + $second) * 1000;
    }

    /** @return array<string,string> */
    private function sources(): array
    {
        return [
            'app.log' => $this->makeFile('app', [
                [0, 'info', 'started'],
                [30, 'error', 'payment failed {"status":500}'],
                [90, 'info', 'done'],
            ]),
            'api.log' => $this->makeFile('api', [
                [15, 'warning', 'slow upstream'],
                [60, 'error', 'timeout {"status":504}'],
            ]),
        ];
    }

    public function test_query_returns_every_record_of_the_window(): void
    {
        $result = $this->explorer()->query($this->sources(), '', $this->at(0), $this->at(120));

        self::assertSame(5, $result['total']);
        self::assertFalse($result['truncated']);
        self::assertCount(5, $result['rows']);
    }

    public function test_rows_come_back_newest_first(): void
    {
        $result = $this->explorer()->query($this->sources(), '', $this->at(0), $this->at(120));

        $timestamps = array_map(static fn (array $r): ?int => $r['ts'], $result['rows']);
        self::assertSame([$this->at(90), $this->at(60), $this->at(30), $this->at(15), $this->at(0)], $timestamps);
    }

    public function test_logql_narrows_the_result(): void
    {
        $result = $this->explorer()->query($this->sources(), '{level="error"}', $this->at(0), $this->at(120));

        self::assertSame(2, $result['total']);
        foreach ($result['rows'] as $row) {
            self::assertSame('error', $row['level']);
        }
    }

    public function test_stream_selector_can_pick_a_single_file(): void
    {
        $result = $this->explorer()->query($this->sources(), '{file="api.log"}', $this->at(0), $this->at(120));

        self::assertSame(2, $result['total']);
        self::assertSame(['api.log', 'api.log'], array_column($result['rows'], 'file'));
    }

    public function test_label_filter_on_json_context(): void
    {
        $result = $this->explorer()->query($this->sources(), '| json | status >= 504', $this->at(0), $this->at(120));

        self::assertSame(1, $result['total']);
        self::assertStringContainsString('timeout', $result['rows'][0]['message']);
    }

    public function test_the_time_window_is_honoured(): void
    {
        $result = $this->explorer()->query($this->sources(), '', $this->at(20), $this->at(70));

        self::assertSame(2, $result['total']);   // 30 and 60 only
    }

    public function test_histogram_counts_records_per_bucket_and_level(): void
    {
        $histogram = $this->explorer()->histogram($this->sources(), '', $this->at(0), $this->at(120));

        self::assertSame(5, $histogram['total']);
        self::assertGreaterThan(0, \count($histogram['buckets']));

        $counted = array_sum(array_column($histogram['buckets'], 'count'));
        self::assertSame(5, $counted, 'every record must land in exactly one bucket');

        $levels = [];
        foreach ($histogram['buckets'] as $bucket) {
            foreach ($bucket['levels'] as $level => $n) {
                $levels[$level] = ($levels[$level] ?? 0) + $n;
            }
        }
        self::assertSame(['info' => 2, 'warning' => 1, 'error' => 2], array_intersect_key($levels, ['info' => 0, 'warning' => 0, 'error' => 0]));
    }

    public function test_histogram_buckets_are_aligned_and_ordered(): void
    {
        $histogram = $this->explorer()->histogram($this->sources(), '', $this->at(0), $this->at(120));

        $previous = null;
        foreach ($histogram['buckets'] as $bucket) {
            self::assertSame(0, ($bucket['ts'] - $histogram['from']) % $histogram['interval'], 'buckets must be aligned on the interval');
            if (null !== $previous) {
                self::assertGreaterThan($previous, $bucket['ts'], 'buckets must be ordered');
            }
            $previous = $bucket['ts'];
        }
    }

    public function test_histogram_respects_the_query(): void
    {
        $histogram = $this->explorer()->histogram($this->sources(), '{level="error"}', $this->at(0), $this->at(120));

        self::assertSame(2, $histogram['total']);
    }

    public function test_bucket_width_grows_with_the_window(): void
    {
        $explorer = $this->explorer();

        self::assertSame(1_000, $explorer->bucketWidth(0, 60_000));            // 1 min  → 1 s
        self::assertSame(60_000, $explorer->bucketWidth(0, 3_600_000));        // 1 h    → 1 min
        self::assertSame(1_800_000, $explorer->bucketWidth(0, 86_400_000));    // 24 h   → 30 min
    }

    /**
     * In production the telemetry file *is* the application's log, so a user can pick a
     * gigabyte-sized file in the Fields panel and get an empty list. The count is what lets the
     * UI say why instead of looking broken.
     */
    public function test_skipped_telemetry_is_counted_so_an_empty_list_can_explain_itself(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'logui_telemetry_').'.log';
        $this->files[] = $path;

        $base = strtotime(self::BASE.' UTC');
        $lines = [];
        for ($i = 0; $i < 3; ++$i) {
            $lines[] = 'LOGUI@'.json_encode([
                'id' => sprintf('%016x', $i),
                'type' => 'cli',
                'at' => gmdate('Y-m-d\TH:i:sP', $base + $i),
                'label' => 'scheduler:run',
            ]);
        }
        file_put_contents($path, implode("\n", $lines)."\n");

        $histogram = $this->explorer()->histogram(['prod.log' => $path], '', $this->at(0), $this->at(60));

        self::assertSame(0, $histogram['matched'], 'telemetry is never shown in the explorer');
        self::assertSame(3, $histogram['telemetry'], 'but it is counted, so the UI can explain the emptiness');
    }

    public function test_a_file_with_real_records_reports_no_telemetry(): void
    {
        $histogram = $this->explorer()->histogram($this->sources(), '', $this->at(0), $this->at(120));

        self::assertSame(0, $histogram['telemetry']);
        self::assertSame(5, $histogram['matched']);
    }

    public function test_tail_from_reads_only_what_was_appended(): void
    {
        $path = $this->makeFile('live', [[0, 'info', 'before']]);
        $offset = $this->sizeOf($path);

        $explorer = $this->explorer();
        $evaluator = $explorer->evaluatorFor('');

        // Nothing new yet.
        self::assertCount(0, iterator_to_array($explorer->tailFrom('live.log', $path, $offset, $evaluator), false));

        file_put_contents($path, sprintf("[%s] app.ERROR: after\n", gmdate('Y-m-d\TH:i:sP', strtotime(self::BASE.' UTC') + 5)), \FILE_APPEND);

        $rows = iterator_to_array($explorer->tailFrom('live.log', $path, $offset, $evaluator), false);

        self::assertCount(1, $rows, 'only the appended record is returned');
        self::assertStringContainsString('after', $rows[0]['message']);
    }

    public function test_tail_from_applies_the_query(): void
    {
        $path = $this->makeFile('live', [[0, 'info', 'before']]);
        $offset = $this->sizeOf($path);

        $explorer = $this->explorer();
        $evaluator = $explorer->evaluatorFor('{level="error"}');

        $base = strtotime(self::BASE.' UTC');
        file_put_contents($path, sprintf(
            "[%s] app.INFO: noise\n[%s] app.ERROR: boom\n",
            gmdate('Y-m-d\TH:i:sP', $base + 5),
            gmdate('Y-m-d\TH:i:sP', $base + 6),
        ), \FILE_APPEND);

        $rows = iterator_to_array($explorer->tailFrom('live.log', $path, $offset, $evaluator), false);

        self::assertCount(1, $rows);
        self::assertStringContainsString('boom', $rows[0]['message']);
    }
}

<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Core\Storage;

use Aleblanc\LogUi\Core\Stats\Timings;

/**
 * Reads several log files at once and merges them into a single, chronologically ordered stream —
 * the "all logs" view.
 *
 * Each source is already sorted (log files are append-only), so merging them is a k-way merge:
 * repeatedly take the oldest of the current heads. With the handful of files a project exposes,
 * a linear scan for the minimum is cheaper and far simpler than maintaining a heap.
 *
 * Every row is tagged with its source via the `file` label, which makes it filterable through
 * the LogQL stream selector: `{file="var/log/dev.log"}`.
 *
 * @phpstan-import-type Row from PlainLogReader
 */
final class MergedLogReader
{
    public function __construct(private readonly PlainLogReader $reader)
    {
    }

    /**
     * Tolerance on a file's mtime: a late flush or a small clock step must not hide its last lines.
     */
    private const MTIME_SLACK_MS = 60_000;

    /** How often the deadline is checked while merging — reading the clock per row would cost. */
    private const DEADLINE_CHECK_EVERY = 256;

    /**
     * Merges the time window [$fromMs, $toMs] across every source.
     *
     * Files last written before the window are not opened at all (see {@see sourcesSince()}): with
     * one rotated file per day, that is most of them.
     *
     * @param array<string,string> $sources  public ref => absolute path
     * @param int                  $maxBytes budget **per source**
     * @param Timings|null         $timings  collects open/seek time, per-file cost and file counters
     * @param float|null           $deadline microtime(true) after which reading stops — covers the
     *                                       opening of the files too, not only the merge; a stop
     *                                       is reported through {@see Timings::budgetHit()}
     *
     * @return \Generator<int,Row>
     */
    public function readRange(array $sources, int $fromMs, int $toMs, int $maxBytes = PlainLogReader::DEFAULT_RANGE_BYTES, ?Timings $timings = null, ?float $deadline = null): \Generator
    {
        $open = $this->sourcesSince($sources, $fromMs);
        $timings?->files(\count($sources), \count($open));

        $streams = [];
        foreach ($open as $ref => $path) {
            $report = null === $timings ? null : static function (float $seekMs, int $bytes, int $rows, int $size) use ($timings, $ref): void {
                $timings->fileRead((string) $ref, $seekMs, $bytes, $rows, $size);
            };
            $streams[$ref] = $this->reader->readRange($path, $fromMs, $toMs, $maxBytes, $report);
        }

        yield from $this->merge($streams, $timings, $deadline);
    }

    /**
     * The sources that may hold a line at or after $fromMs.
     *
     * A log file only ever grows at its end, so nothing in it is newer than its last modification:
     * one stat rules it out, where opening it costs a binary search and a parsed block. A file whose
     * mtime cannot be read is kept — skipping it would hide lines silently.
     *
     * @param array<string,string> $sources
     *
     * @return array<string,string>
     */
    public function sourcesSince(array $sources, int $fromMs): array
    {
        return array_filter($sources, static function (string $path) use ($fromMs): bool {
            $mtime = @filemtime($path);

            return false === $mtime || $mtime * 1000 + self::MTIME_SLACK_MS >= $fromMs;
        });
    }

    /**
     * Merges the tail of every source — used when no time range is given.
     *
     * @param array<string,string> $sources  public ref => absolute path
     * @param int                  $maxBytes budget **per source**
     *
     * @return \Generator<int,Row>
     */
    public function readTail(array $sources, int $maxBytes = PlainLogReader::DEFAULT_TAIL_BYTES): \Generator
    {
        $streams = [];
        foreach ($sources as $ref => $path) {
            $streams[$ref] = $this->reader->readTail($path, $maxBytes);
        }

        yield from $this->merge($streams);
    }

    /**
     * Merges what was appended to each source after a byte offset — used by the live tail.
     *
     * @param array<string,string> $sources public ref => absolute path
     *
     * @return \Generator<int,Row>
     */
    public function readFrom(array $sources, int $offset): \Generator
    {
        $streams = [];
        foreach ($sources as $ref => $path) {
            $streams[$ref] = $this->reader->readFrom($path, $offset);
        }

        yield from $this->merge($streams);
    }

    /**
     * Records of one source whose first line begins at a byte offset in `[from, to)`.
     *
     * Context lines are a single-file concern (the offset only means anything within its own file),
     * so this is a thin passthrough to {@see PlainLogReader::readBetween()}.
     *
     * @return \Generator<int,Row>
     */
    public function readBetween(string $path, int $from, int $to): \Generator
    {
        yield from $this->reader->readBetween($path, $from, $to);
    }

    /**
     * @param array<string,\Generator<int,Row>> $streams
     *
     * @return \Generator<int,Row>
     */
    private function merge(array $streams, ?Timings $timings = null, ?float $deadline = null): \Generator
    {
        /** @var array<string,Row> $heads */
        $heads = [];
        /** @var array<string,int> $order ordering key of each head */
        $order = [];
        /** @var array<string,int> $lastTs last real timestamp seen per source */
        $lastTs = [];

        // Opening: every source has to produce its first row (seek + first parsed block) before the
        // oldest one can be picked. On many files this phase alone can outlast the budget, so the
        // deadline is checked between files, not only once rows flow.
        $openStarted = hrtime(true);
        foreach ($streams as $ref => $stream) {
            if (null !== $deadline && microtime(true) > $deadline) {
                $timings?->budgetHit();
                $timings?->add('open', (hrtime(true) - $openStarted) / 1e6);

                return;
            }
            $this->advance($ref, $stream, $heads, $order, $lastTs, $timings);
        }
        $timings?->add('open', (hrtime(true) - $openStarted) / 1e6);

        $merged = 0;
        while ([] !== $heads) {
            // Rows the caller filters out never reach its own budget check: count them here.
            if (null !== $deadline && 0 === ++$merged % self::DEADLINE_CHECK_EVERY && microtime(true) > $deadline) {
                $timings?->budgetHit();

                return;
            }

            // Oldest head wins; ties keep a stable order so a file never starves.
            $pick = null;
            $pickOrder = \PHP_INT_MAX;
            foreach ($order as $ref => $key) {
                if ($key < $pickOrder) {
                    $pick = $ref;
                    $pickOrder = $key;
                }
            }
            if (null === $pick) {
                return;
            }

            $row = $heads[$pick];
            $row['file'] = $pick;
            yield $row;

            $this->advance($pick, $streams[$pick], $heads, $order, $lastTs, $timings);
        }
    }

    /**
     * Pulls the next row of a source and refreshes its ordering key.
     *
     * Undatable rows (raw lines, orphaned fragments) inherit the last timestamp seen on their own
     * source: they belong just after it, so they stay attached to their context instead of being
     * flushed to the beginning or the end of the merged stream.
     *
     * @param array<string,Row>   $heads
     * @param array<string,int>   $order
     * @param array<string,int>   $lastTs
     * @param \Generator<int,Row> $stream
     */
    private function advance(string $ref, \Generator $stream, array &$heads, array &$order, array &$lastTs, ?Timings $timings = null): void
    {
        // Generators are lazy: the seek and the parsing happen inside valid()/next(), so timing
        // these calls is timing the file itself.
        $t = null === $timings ? 0 : hrtime(true);
        $valid = $stream->valid();
        if (!$valid) {
            $timings?->fileTime($ref, (hrtime(true) - $t) / 1e6);
            unset($heads[$ref], $order[$ref]);

            return;
        }

        $row = $stream->current();
        $stream->next();
        $timings?->fileTime($ref, (hrtime(true) - $t) / 1e6);

        $ts = $row['ts'];
        if (null !== $ts) {
            $lastTs[$ref] = $ts;
        }

        $heads[$ref] = $row;
        $order[$ref] = $ts ?? ($lastTs[$ref] ?? 0);
    }
}

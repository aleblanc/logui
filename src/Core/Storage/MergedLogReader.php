<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Core\Storage;

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
     * Merges the time window [$fromMs, $toMs] across every source.
     *
     * @param array<string,string> $sources  public ref => absolute path
     * @param int                  $maxBytes budget **per source**
     *
     * @return \Generator<int,Row>
     */
    public function readRange(array $sources, int $fromMs, int $toMs, int $maxBytes = PlainLogReader::DEFAULT_RANGE_BYTES): \Generator
    {
        $streams = [];
        foreach ($sources as $ref => $path) {
            $streams[$ref] = $this->reader->readRange($path, $fromMs, $toMs, $maxBytes);
        }

        yield from $this->merge($streams);
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
     * @param array<string,\Generator<int,Row>> $streams
     *
     * @return \Generator<int,Row>
     */
    private function merge(array $streams): \Generator
    {
        /** @var array<string,Row> $heads */
        $heads = [];
        /** @var array<string,int> $order ordering key of each head */
        $order = [];
        /** @var array<string,int> $lastTs last real timestamp seen per source */
        $lastTs = [];

        foreach ($streams as $ref => $stream) {
            $this->advance($ref, $stream, $heads, $order, $lastTs);
        }

        while ([] !== $heads) {
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

            $this->advance($pick, $streams[$pick], $heads, $order, $lastTs);
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
    private function advance(string $ref, \Generator $stream, array &$heads, array &$order, array &$lastTs): void
    {
        if (!$stream->valid()) {
            unset($heads[$ref], $order[$ref]);

            return;
        }

        $row = $stream->current();
        $stream->next();

        $ts = $row['ts'];
        if (null !== $ts) {
            $lastTs[$ref] = $ts;
        }

        $heads[$ref] = $row;
        $order[$ref] = $ts ?? ($lastTs[$ref] ?? 0);
    }
}

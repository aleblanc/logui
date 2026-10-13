<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Core\Storage;

/**
 * Locates, by binary search on byte offsets, where a time range starts inside a log file.
 *
 * Log files are append-only and chronological, so the offset of a timestamp can be found with
 * O(log n) seeks instead of scanning the file. This is what makes "zoom into yesterday 14:00-15:00"
 * affordable on a multi-GB file **without any index or database** — LogUI keeps its promise of
 * having no separate store.
 *
 * The seeker is deliberately decoupled from {@see PlainLogReader}: it receives a callable that
 * extracts a timestamp from a raw line, so it can be unit-tested on its own and the two classes
 * never depend on each other.
 */
final class LogSeeker
{
    /**
     * Below this size a binary search is pointless: reading the whole file costs less than the
     * seeks it would take to avoid it.
     */
    public const MIN_SEARCHABLE_BYTES = 256 * 1024;

    /** Granularity at which the search stops narrowing and hands over to a linear scan. */
    private const BLOCK_BYTES = 64 * 1024;

    /** Probing gives up after this many bytes without finding a datable line. */
    private const PROBE_BYTES = 128 * 1024;

    /** @var \Closure(string):?int */
    private readonly \Closure $timestampOf;

    /**
     * @param callable(string):?int $timestampOf returns the epoch-ms of a raw line, or null when
     *                                           the line carries no usable date
     */
    public function __construct(callable $timestampOf)
    {
        $this->timestampOf = $timestampOf(...);
    }

    /**
     * Byte offset from which to start reading to cover timestamps >= $targetMs.
     *
     * The returned offset is always **at or before** the first matching line and lands on a line
     * boundary, so the caller can read forward and filter. It is intentionally conservative: one
     * block of margin is kept so that slightly out-of-order lines (concatenated files, a clock
     * stepping backwards) are not skipped.
     *
     * @param resource $handle
     * @param int      $size   file size in bytes
     */
    public function offsetFor($handle, int $size, int $targetMs): int
    {
        if ($size <= self::MIN_SEARCHABLE_BYTES) {
            return 0;
        }

        $lo = 0;
        $hi = $size;

        while ($hi - $lo > self::BLOCK_BYTES) {
            $mid = intdiv($lo + $hi, 2);
            $ts = $this->probe($handle, $mid, $hi);

            if (null === $ts) {
                // No datable line between $mid and $hi: the answer cannot be proven to be in the
                // upper half, so keep the lower one rather than risk skipping lines.
                $hi = $mid;
                continue;
            }

            if ($ts >= $targetMs) {
                $hi = $mid;
            } else {
                $lo = $mid;
            }
        }

        // One block of margin, then realign on a line boundary.
        return max(0, $lo - self::BLOCK_BYTES);
    }

    /**
     * Timestamp of the first datable line at or after $offset.
     *
     * @param resource $handle
     *
     * @return int|null null when no datable line is found before $limit (or within PROBE_BYTES)
     */
    private function probe($handle, int $offset, int $limit): ?int
    {
        if (-1 === fseek($handle, $offset)) {
            return null;
        }

        // Unless we are at the very beginning, the first read lands mid-line: drop it.
        if ($offset > 0) {
            fgets($handle);
        }

        $budget = self::PROBE_BYTES;
        while ($budget > 0 && ftell($handle) < $limit) {
            $line = fgets($handle);
            if (false === $line) {
                return null;
            }
            $budget -= \strlen($line);

            $ts = ($this->timestampOf)(rtrim($line, "\r\n"));
            if (null !== $ts) {
                return $ts;
            }
        }

        return null;
    }
}

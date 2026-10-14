<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Core\Storage;

/**
 * Shrinks a log file in place by keeping only its tail.
 *
 * A log that has grown to gigabytes is mostly history nobody will read; keeping the last few
 * megabytes reclaims the disk while leaving the recent, useful part intact. The kept data always
 * starts on a record boundary — the partial first line the cut lands in is dropped — so the file
 * stays parseable.
 *
 * The rewrite happens in place (same inode) under an exclusive lock, so a logger with the file
 * already open keeps writing to it afterwards. It is a maintenance action, not a hot path: a
 * concurrent append during the brief rewrite window may be lost, which is an acceptable trade for
 * not orphaning the writer's handle.
 */
final class LogTruncator
{
    /**
     * Keep the last $keepBytes of the file (0 empties it). Returns the new size in bytes.
     *
     * Bounded memory: at most $keepBytes are read into memory, so the caller must keep that figure
     * sane (the UI offers a few fixed sizes).
     */
    public static function keepTail(string $path, int $keepBytes): int
    {
        if (!is_file($path)) {
            return 0;
        }

        clearstatcache(true, $path);
        $size = (int) filesize($path);

        // Already small enough: nothing to do. (keepBytes = 0 falls through and empties the file.)
        if ($keepBytes > 0 && $size <= $keepBytes) {
            return $size;
        }

        $handle = fopen($path, 'r+b');
        if (false === $handle) {
            return $size;
        }

        try {
            flock($handle, \LOCK_EX);

            $tail = '';
            if ($keepBytes > 0) {
                fseek($handle, max(0, $size - $keepBytes));
                $chunk = stream_get_contents($handle);
                $chunk = false === $chunk ? '' : $chunk;
                // Drop the partial first line so the kept data starts on a whole record. A tail
                // with no newline at all (one giant line) is kept as-is — there is no better cut.
                $newline = strpos($chunk, "\n");
                $tail = false !== $newline ? substr($chunk, $newline + 1) : $chunk;
            }

            rewind($handle);
            fwrite($handle, $tail);
            ftruncate($handle, \strlen($tail));
            fflush($handle);

            return \strlen($tail);
        } finally {
            flock($handle, \LOCK_UN);
            fclose($handle);
        }
    }
}

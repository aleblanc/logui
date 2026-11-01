<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Core\Query;

/**
 * Per-file histogram tallies kept on disk, so a rotated log is counted once and never again.
 *
 * A key names a file *as it is* (path, size, mtime) and the question asked of it (interval and
 * filters): a file that changes gets a new key, so an entry is never stale — at worst unused. Only
 * files that have gone quiet are stored ({@see LogExplorer}), the one being written is always read.
 *
 * Entries are small JSON files under one directory. Unused ones are swept now and then by age;
 * deleting the whole directory is always safe.
 */
final class HistogramCache
{
    /** Bumped whenever the tally format or the counting rules change, which retires old entries. */
    private const VERSION = 1;

    /** Entries not read for this long are removed by the occasional sweep. */
    private const MAX_IDLE_SECONDS = 30 * 86400;

    /** One write in this many triggers a sweep — cheap enough, rare enough. */
    private const SWEEP_EVERY = 50;

    public function __construct(private readonly string $directory)
    {
    }

    /** @param array<string,mixed> $question interval, filters — anything the tally depends on */
    public function key(string $path, int $size, int $mtime, array $question): string
    {
        return sha1(json_encode([self::VERSION, $path, $size, $mtime, $question], \JSON_THROW_ON_ERROR));
    }

    public function get(string $key): ?HistogramTally
    {
        $file = $this->file($key);
        $json = is_file($file) ? @file_get_contents($file) : false;
        if (false === $json) {
            return null;
        }

        $data = json_decode($json, true);
        $tally = \is_array($data) ? HistogramTally::import($data) : null;
        if (null !== $tally) {
            @touch($file); // keeps an entry in use away from the sweep
        }

        return $tally;
    }

    public function put(string $key, HistogramTally $tally): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0o775, true) && !is_dir($this->directory)) {
            return; // an unwritable cache only costs speed
        }

        $json = json_encode($tally->export(), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION);
        if (false === $json) {
            return;
        }

        // Write then rename: a reader never sees half an entry.
        $file = $this->file($key);
        $tmp = $file.'.'.bin2hex(random_bytes(4)).'.tmp';
        if (false !== @file_put_contents($tmp, $json)) {
            @rename($tmp, $file);
        }
        @unlink($tmp);

        if (0 === random_int(0, self::SWEEP_EVERY - 1)) {
            $this->sweep();
        }
    }

    /**
     * Takes the warm-up lock without waiting, so only one request at a time fills the cache.
     *
     * @return resource|null the lock to pass to {@see unlock()}, or null when someone else holds it
     */
    public function lock()
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0o775, true) && !is_dir($this->directory)) {
            return null;
        }
        $handle = @fopen($this->directory.'/.warm.lock', 'c');
        if (false === $handle) {
            return null;
        }
        if (!flock($handle, \LOCK_EX | \LOCK_NB)) {
            fclose($handle);

            return null;
        }

        return $handle;
    }

    /** @param resource $lock */
    public function unlock($lock): void
    {
        flock($lock, \LOCK_UN);
        fclose($lock);
    }

    private function sweep(): void
    {
        $limit = time() - self::MAX_IDLE_SECONDS;
        foreach (glob($this->directory.'/*.json') ?: [] as $file) {
            $mtime = @filemtime($file);
            if (false !== $mtime && $mtime < $limit) {
                @unlink($file);
            }
        }
    }

    private function file(string $key): string
    {
        return $this->directory.'/'.$key.'.json';
    }
}

<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Core\Query;

/**
 * Where the {@see MinuteIndex} of each log file lives: one directory per file under a root
 * (`%kernel.cache_dir%/logui` in the bundle). Deleting the root is always safe — indexes are
 * rebuilt as pages are loaded.
 */
final class MinuteIndexStore
{
    /** Index directories untouched for this long belong to files that are gone or no longer read. */
    private const MAX_IDLE_SECONDS = 30 * 86400;

    /** One open in this many sweeps idle indexes — cheap enough, rare enough. */
    private const SWEEP_EVERY = 200;

    public function __construct(private readonly string $directory)
    {
    }

    /**
     * @param list<string> $selfNeedles  paths marking LogUI's own traffic — part of what the
     *                                   counts depend on, so a change rebuilds the index
     * @param int          $patternEvery pattern sampling rate — same
     */
    public function open(string $path, array $selfNeedles, int $patternEvery): MinuteIndex
    {
        $real = realpath($path) ?: $path;
        $fingerprint = sha1(json_encode([MinuteIndex::VERSION, $selfNeedles, $patternEvery], \JSON_THROW_ON_ERROR));

        if (0 === random_int(0, self::SWEEP_EVERY - 1)) {
            $this->sweep();
        }

        return MinuteIndex::open($this->directory.'/'.sha1($real), $real, $fingerprint);
    }

    private function sweep(): void
    {
        $limit = time() - self::MAX_IDLE_SECONDS;
        foreach (glob($this->directory.'/*/meta.json') ?: [] as $meta) {
            $mtime = @filemtime($meta);
            if (false === $mtime || $mtime >= $limit) {
                continue;
            }
            $dir = \dirname($meta);
            foreach (glob($dir.'/{,.}*', \GLOB_BRACE) ?: [] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            @rmdir($dir);
        }
    }
}

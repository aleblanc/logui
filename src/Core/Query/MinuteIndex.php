<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Core\Query;

use Aleblanc\LogUi\Core\Storage\MergedLogReader;

/**
 * Incremental histogram index of one log file: what it holds, minute by minute, up to a byte
 * offset.
 *
 * Log files only grow, so the index remembers how far it has read and each catch-up reads the
 * bytes appended since — like the live tail. A page load then costs what was written since the
 * previous one, whatever the size of the file or the width of the window.
 *
 * **What is stored** — per minute, a count for every combination of (type, level, channel, own
 * traffic, datable) actually seen: a small cube from which the bars, the facets and any facet
 * filter are all derived at query time, so ticking `error` or showing LogUI's own traffic needs no
 * new index. Request cells also carry their failures, SQL count and duration bins. Patterns are
 * kept per hour and level (the most frequent {@see PATTERNS_PER_HOUR}).
 *
 * **Layout** — one directory per file: `meta.json` (offset, identity, resume state) and one
 * `d-<day>.json` per UTC day, so a catch-up rewrites only the days it touched and a window loads
 * only the days it covers.
 *
 * **Identity** — the index is thrown away and rebuilt when the file is no longer the one it was
 * built from: another inode (rotated by rename), a different first line (recreated), a size below
 * the offset (truncated), or different bytes just before the offset (truncated, then regrown past
 * the old size between two loads).
 *
 * Minutes are the resolution: a window edge is honoured to the minute, and bars narrower than a
 * minute cannot come from here (the explorer reads short windows directly).
 *
 * @phpstan-import-type Bins from HistogramTally
 *
 * @phpstan-type Cell array{0:int,1:int,2:int,3:Bins} count, failed, sql, duration bins
 * @phpstan-type Chunk array{m:array<int,array<string,Cell>>,p:array<int,array<string,array<string,array{0:int,1:string}>>>}
 */
final class MinuteIndex
{
    /** Bumped when the stored format or the counting rules change: older indexes are rebuilt. */
    public const VERSION = 1;

    /** Most frequent patterns kept per hour and level. */
    private const PATTERNS_PER_HOUR = 200;

    private const HEAD_BYTES = 4096;
    private const TAIL_BYTES = 256;
    private const SEP = "\x1f";

    private int $offset = 0;
    private bool $continuation = false;
    private int $sampled = 0;
    private ?int $lastTs = null;
    private string $head = '';

    /** @var array<int,Chunk> UTC day number => chunk, loaded on demand */
    private array $days = [];

    /** @var array<int,true> days changed since the last save */
    private array $dirty = [];

    private Patterns $keys;

    /** @var resource|null */
    private $lock;

    private function __construct(
        private readonly string $dir,
        private readonly string $path,
        private readonly string $fingerprint,
    ) {
        $this->keys = new Patterns();
    }

    /**
     * The index of $path under $dir, validated against the file as it is now — reset when the file
     * was rotated, recreated or truncated since.
     *
     * @param string $fingerprint what the counts depend on besides the file (version, rules)
     */
    public static function open(string $dir, string $path, string $fingerprint): self
    {
        $index = new self($dir, $path, $fingerprint);

        $meta = is_file($dir.'/meta.json') ? json_decode((string) @file_get_contents($dir.'/meta.json'), true) : null;
        if (\is_array($meta) && $index->accepts($meta)) {
            $index->offset = (int) $meta['offset'];
            $index->continuation = (bool) $meta['continuation'];
            $index->sampled = (int) $meta['sampled'];
            $index->lastTs = isset($meta['lastTs']) ? (int) $meta['lastTs'] : null;
            $index->head = (string) $meta['head'];
        } elseif (is_dir($dir)) {
            $index->wipe();
        }

        return $index;
    }

    /** Bytes of the file the index covers. */
    public function offset(): int
    {
        return $this->offset;
    }

    /**
     * Takes this file's lock without waiting: only one request catches an index up at a time. A
     * request that does not get it reads the index as it is.
     */
    public function lock(): bool
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0o775, true) && !is_dir($this->dir)) {
            return false;
        }
        $handle = @fopen($this->dir.'/.lock', 'c');
        if (false === $handle) {
            return false;
        }
        if (!flock($handle, \LOCK_EX | \LOCK_NB)) {
            fclose($handle);

            return false;
        }
        $this->lock = $handle;

        return true;
    }

    public function unlock(): void
    {
        if (null !== $this->lock) {
            flock($this->lock, \LOCK_UN);
            fclose($this->lock);
            $this->lock = null;
        }
    }

    /**
     * Reads what was appended since the last catch-up (or until $deadline) and saves the progress.
     * Must hold the lock.
     *
     * @param list<string> $selfNeedles paths whose mention marks LogUI's own traffic
     *
     * @return bool whether the index now reaches the end of the file
     */
    public function catchUp(MergedLogReader $reader, array $selfNeedles, int $patternEvery, ?float $deadline): bool
    {
        $scan = $reader->scanFrom($this->path, ['offset' => $this->offset, 'continuation' => $this->continuation, 'sampled' => $this->sampled], $selfNeedles, $patternEvery, $deadline);
        foreach ($scan as $tick) {
            $this->add($tick);
        }
        $state = $scan->getReturn();

        $this->offset = $state['offset'];
        $this->continuation = $state['continuation'];
        $this->sampled = $state['sampled'];
        if ('' === $this->head && $this->offset > 0) {
            $this->head = self::headOf($this->path);
        }
        $this->save();

        return $state['done'];
    }

    /**
     * Adds the minutes of [$fromMs, $toMs] to $tally, filtered by $selection's facets. LogUI's own
     * traffic is left out unless $selection shows it.
     */
    public function tallyInto(HistogramTally $tally, int $fromMs, int $toMs, Selection $selection, string $ref): void
    {
        $hideSelf = [] !== $selection->excluding();
        $fromMinute = intdiv($fromMs, 60_000);
        $toMinute = intdiv($toMs, 60_000);

        for ($day = intdiv($fromMinute, 1440); $day <= intdiv($toMinute, 1440); ++$day) {
            $chunk = $this->chunk($day);

            foreach ($chunk['m'] as $minute => $cells) {
                if ($minute < $fromMinute || $minute > $toMinute) {
                    continue;
                }
                foreach ($cells as $key => $cell) {
                    [$type, $level, $channel, $self, $dated] = explode(self::SEP, (string) $key);
                    if ($hideSelf && '1' === $self) {
                        continue;
                    }
                    $tally->facet($type, $level, $channel, $ref, $cell[0]);
                    if (!$selection->matchesFacetValues($type, $level, $channel)) {
                        continue;
                    }
                    $requests = 'request' === $type || 'command' === $type ? $cell[0] : 0;
                    $tally->cell($minute * 60_000, '1' === $dated, $level, $cell[0], $requests, $cell[1], $cell[2], $cell[3]);
                }
            }

            foreach ($chunk['p'] as $hour => $levels) {
                $hourStart = $hour * 3_600_000;
                if ($hourStart + 3_599_999 < $fromMs || $hourStart > $toMs) {
                    continue;
                }
                foreach ($levels as $level => $patterns) {
                    if (!$selection->matchesLevel((string) $level)) {
                        continue;
                    }
                    foreach ($patterns as $pattern => [$count, $sample]) {
                        $tally->patternKey((string) $pattern, $sample, (string) $level, $count);
                    }
                }
            }
        }
    }

    /** @param array<string,mixed> $tick a {@see \Aleblanc\LogUi\Core\Storage\PlainLogReader::scanFrom()} tick */
    private function add(array $tick): void
    {
        /** @var int|null $ts */
        $ts = $tick['ts'];
        $dated = null !== $ts;
        if ($dated) {
            $this->lastTs = $ts;
        } else {
            // An undatable line belongs where it sits: just after the last dated one.
            $ts = $this->lastTs;
            if (null === $ts) {
                return; // nothing dated yet: there is no minute to file it under
            }
        }

        /** @var string $level */
        $level = '' !== $tick['level'] ? $tick['level'] : 'other';
        /** @var string $type */
        $type = $tick['type'];
        $minute = intdiv($ts, 60_000);
        $day = intdiv($minute, 1440);
        $chunk = &$this->chunkRef($day);
        $this->dirty[$day] = true;

        $key = $type.self::SEP.$level.self::SEP.$tick['channel'].self::SEP.($tick['self'] ? '1' : '0').self::SEP.($dated ? '1' : '0');
        $cell = &$chunk['m'][$minute][$key];
        $cell ??= [0, 0, 0, []];
        ++$cell[0];
        /** @var array<string,mixed>|null $profile */
        $profile = $tick['profile'];
        if (null !== $profile) {
            $cell[1] += ($profile['failed'] ?? false) ? 1 : 0;
            $cell[2] += (int) ($profile['sql'] ?? 0);
            $bin = HistogramTally::durationBin((float) ($profile['duration_ms'] ?? 0));
            $cell[3][$bin] = ($cell[3][$bin] ?? 0) + 1;
        }
        unset($cell);

        /** @var string|null $message */
        $message = $tick['message'];
        if (null !== $message && !$tick['self']) {
            $pattern = $this->keys->keyOf($message);
            $entry = &$chunk['p'][intdiv($ts, 3_600_000)][$level][$pattern];
            $entry ??= [0, substr(strtok($message, "\n") ?: '', 0, 300)];
            $entry[0] += (int) $tick['weight'];
            unset($entry);
        }
        unset($chunk);
    }

    /** @return Chunk */
    private function chunk(int $day): array
    {
        return $this->chunkRef($day);
    }

    /** @return Chunk */
    private function &chunkRef(int $day): array
    {
        if (!isset($this->days[$day])) {
            $file = $this->dayFile($day);
            $data = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;
            /** @var Chunk $chunk */
            $chunk = \is_array($data) && \is_array($data['m'] ?? null) && \is_array($data['p'] ?? null) ? $data : ['m' => [], 'p' => []];
            $this->days[$day] = $chunk;
        }

        return $this->days[$day];
    }

    private function save(): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0o775, true) && !is_dir($this->dir)) {
            return; // an unwritable cache only costs speed
        }

        foreach (array_keys($this->dirty) as $day) {
            $chunk = &$this->days[$day];
            // Keep each hour's most frequent patterns; the long tail would only grow the file.
            foreach ($chunk['p'] as &$levels) {
                foreach ($levels as &$patterns) {
                    if (\count($patterns) > self::PATTERNS_PER_HOUR) {
                        uasort($patterns, static fn (array $a, array $b): int => $b[0] <=> $a[0]);
                        $patterns = \array_slice($patterns, 0, self::PATTERNS_PER_HOUR, true);
                    }
                }
                unset($patterns);
            }
            unset($levels);
            $this->write($this->dayFile($day), $chunk);
            unset($chunk);
        }
        $this->dirty = [];

        // Meta last: if anything above failed half-way, the next catch-up re-reads from the old
        // offset rather than skipping lines. (It may then count a few twice — far better than
        // losing them, and only after a crash.)
        $this->write($this->dir.'/meta.json', [
            'version' => self::VERSION,
            'fingerprint' => $this->fingerprint,
            'path' => $this->path,
            'inode' => (int) @fileinode($this->path),
            'offset' => $this->offset,
            'tail' => self::tailOf($this->path, $this->offset),
            'head' => $this->head,
            'continuation' => $this->continuation,
            'sampled' => $this->sampled,
            'lastTs' => $this->lastTs,
        ]);
    }

    /** @param array<array-key,mixed> $meta */
    private function accepts(array $meta): bool
    {
        if (($meta['version'] ?? null) !== self::VERSION || ($meta['fingerprint'] ?? null) !== $this->fingerprint || ($meta['path'] ?? null) !== $this->path) {
            return false;
        }

        clearstatcache(true, $this->path);
        $offset = (int) ($meta['offset'] ?? 0);
        if ((int) @fileinode($this->path) !== (int) ($meta['inode'] ?? -1) || (int) @filesize($this->path) < $offset) {
            return false;
        }
        if ('' !== ($meta['head'] ?? '') && self::headOf($this->path) !== $meta['head']) {
            return false;
        }

        return self::tailOf($this->path, $offset) === ($meta['tail'] ?? null);
    }

    /** Hash of the first line, once it is complete — '' until then. */
    private static function headOf(string $path): string
    {
        $handle = @fopen($path, 'rb');
        if (false === $handle) {
            return '';
        }
        $line = fgets($handle, self::HEAD_BYTES);
        fclose($handle);

        return false === $line || (!str_ends_with($line, "\n") && \strlen($line) < self::HEAD_BYTES - 1) ? '' : sha1($line);
    }

    /** Hash of the bytes just before $offset. */
    private static function tailOf(string $path, int $offset): string
    {
        if (0 === $offset) {
            return '';
        }
        $handle = @fopen($path, 'rb');
        if (false === $handle) {
            return '';
        }
        $start = max(0, $offset - self::TAIL_BYTES);
        fseek($handle, $start);
        $bytes = (string) fread($handle, $offset - $start);
        fclose($handle);

        return sha1($bytes);
    }

    private function wipe(): void
    {
        foreach (glob($this->dir.'/*.json') ?: [] as $file) {
            @unlink($file);
        }
    }

    private function dayFile(int $day): string
    {
        return $this->dir.'/d-'.$day.'.json';
    }

    /** @param array<string,mixed> $data */
    private function write(string $file, array $data): void
    {
        $json = json_encode($data, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE);
        if (false === $json) {
            return;
        }
        // Write then rename: a reader never sees half a file.
        $tmp = $file.'.'.bin2hex(random_bytes(4)).'.tmp';
        if (false !== @file_put_contents($tmp, $json)) {
            @rename($tmp, $file);
        }
        @unlink($tmp);
    }
}

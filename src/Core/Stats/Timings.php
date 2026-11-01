<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Core\Stats;

/**
 * Where one explorer request spent its time — the breakdown shown in the page footer.
 *
 * Steps are named phases of the request (boot, sources, parse, open, scan…), accumulated in ms.
 * Files get their own counters because reading is where a slow query actually goes: how many
 * sources there were, how many were opened (the rest skipped by mtime), and which ones cost most.
 *
 * Per-request and passed explicitly: never a shared service, so a long-running worker cannot leak
 * one request's numbers into the next.
 */
final class Timings
{
    /** Slowest files listed in the payload — enough to point at the culprit, not a dump. */
    private const TOP_FILES = 10;

    /** @var array<string,float> step => ms */
    private array $steps = [];

    /** @var array<string,array{ms:float,seekMs:float,bytes:int,rows:int,size:int}> */
    private array $files = [];

    private int $filesTotal = 0;
    private int $filesOpened = 0;
    private int $filesCached = 0;
    private bool $budgetHit = false;

    public function add(string $step, float $ms): void
    {
        $this->steps[$step] = ($this->steps[$step] ?? 0.0) + $ms;
    }

    public function get(string $step): float
    {
        return $this->steps[$step] ?? 0.0;
    }

    /**
     * Runs $fn and records how long it took under $step.
     *
     * @template T
     *
     * @param callable():T $fn
     *
     * @return T
     */
    public function measure(string $step, callable $fn): mixed
    {
        $t = hrtime(true);
        try {
            return $fn();
        } finally {
            $this->add($step, (hrtime(true) - $t) / 1e6);
        }
    }

    /** How many sources were asked for, and how many survived the mtime filter. */
    public function files(int $total, int $opened): void
    {
        $this->filesTotal += $total;
        $this->filesOpened += $opened;
    }

    /** One file answered from the histogram cache instead of being read. */
    public function cached(): void
    {
        ++$this->filesCached;
    }

    /** The read stopped on the wall-clock budget: what was returned is partial. */
    public function budgetHit(): void
    {
        $this->budgetHit = true;
    }

    public function wasBudgetHit(): bool
    {
        return $this->budgetHit;
    }

    /** Time spent pulling rows out of one file (seek, read, parse, range filtering). */
    public function fileTime(string $ref, float $ms): void
    {
        $this->file($ref);
        $this->files[$ref]['ms'] += $ms;
    }

    /** One file's read: the binary-search cost, the bytes scanned past the seek point, the rows yielded. */
    public function fileRead(string $ref, float $seekMs, int $bytes, int $rows, int $size): void
    {
        $this->file($ref);
        $this->files[$ref]['seekMs'] += $seekMs;
        $this->files[$ref]['bytes'] += $bytes;
        $this->files[$ref]['rows'] += $rows;
        $this->files[$ref]['size'] = $size;
    }

    /**
     * `steps` as `<name>_ms` plus the file counters, and the slowest files.
     *
     * @return array{steps:array<string,float|int|bool>,files:list<array{file:string,ms:float,seekMs:float,bytes:int,rows:int,size:int}>}
     */
    public function toArray(): array
    {
        $steps = [];
        foreach ($this->steps as $step => $ms) {
            $steps[$step.'_ms'] = round($ms, 1);
        }
        $steps['files_total'] = $this->filesTotal;
        $steps['files_opened'] = $this->filesOpened;
        $steps['files_cached'] = $this->filesCached;
        $steps['budget_hit'] = $this->budgetHit;

        $files = [];
        foreach ($this->files as $ref => $f) {
            $f['ms'] = round($f['ms'], 1);
            $f['seekMs'] = round($f['seekMs'], 1);
            $files[] = ['file' => $ref] + $f;
        }
        // Slowest first: the file to look at is the one on top.
        usort($files, static fn (array $a, array $b): int => $b['ms'] <=> $a['ms']);

        return ['steps' => $steps, 'files' => \array_slice($files, 0, self::TOP_FILES)];
    }

    /** The same steps as a `Server-Timing` header, so the browser's network panel shows them too. */
    public function serverTimingHeader(): string
    {
        $parts = [];
        foreach ($this->steps as $step => $ms) {
            $parts[] = preg_replace('/[^A-Za-z0-9_-]/', '_', $step).';dur='.round($ms, 1);
        }
        $parts[] = 'files;desc="'.$this->filesOpened.'/'.$this->filesTotal.' opened"';

        return implode(', ', $parts);
    }

    private function file(string $ref): void
    {
        $this->files[$ref] ??= ['ms' => 0.0, 'seekMs' => 0.0, 'bytes' => 0, 'rows' => 0, 'size' => 0];
    }
}

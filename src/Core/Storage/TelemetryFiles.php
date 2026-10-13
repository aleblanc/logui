<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Core\Storage;

/**
 * Where the telemetry lines live — one file, or one file per day/month.
 *
 * Rotation is off by default, which keeps the historical behaviour: everything goes to the single
 * configured file and nothing is ever deleted. Turn it on and `var/log/logui.log` becomes
 * `var/log/logui-2026-10-13.log`, the same `{filename}-{date}` shape Monolog's RotatingFileHandler
 * uses, so the files look familiar next to the rest of your logs.
 *
 * This class also *deletes* files, which deserves care: it only ever considers names its own
 * pattern produced, with a date part that parses back to the exact format it writes. A file that
 * merely looks similar — `logui-backup.log`, `logui-2026-10-13.log.gz`, the undated base file —
 * is never a candidate. {@see prune()}.
 */
final class TelemetryFiles
{
    public const NONE = 'none';
    public const DAILY = 'daily';
    public const MONTHLY = 'monthly';

    private const FORMATS = [self::DAILY => 'Y-m-d', self::MONTHLY => 'Y-m'];

    /**
     * @param string $pattern  the configured path, e.g. `/app/var/log/logui.log`
     * @param string $rotation one of none|daily|monthly
     * @param int    $maxFiles how many dated files to keep; 0 keeps every one
     */
    public function __construct(
        private readonly string $pattern,
        private readonly string $rotation = self::NONE,
        private readonly int $maxFiles = 0,
    ) {
    }

    public function rotates(): bool
    {
        return isset(self::FORMATS[$this->rotation]);
    }

    /** The file being written to right now. */
    public function current(?\DateTimeImmutable $now = null): string
    {
        if (!$this->rotates()) {
            return $this->pattern;
        }

        return $this->dated(($now ?? new \DateTimeImmutable())->format(self::FORMATS[$this->rotation]));
    }

    /**
     * Every file worth reading, **newest first** — the order the Requests tab wants, and the order
     * that lets a reader spend a byte budget on the most recent data.
     *
     * @return list<string>
     */
    public function all(): array
    {
        if (!$this->rotates()) {
            return is_file($this->pattern) ? [$this->pattern] : [];
        }

        $dated = $this->dated('*');
        $found = glob($dated) ?: [];

        $files = [];
        foreach ($found as $path) {
            if (null !== $this->dateOf($path)) {
                $files[] = $path;
            }
        }

        // The dates are fixed-width and zero-padded, so a plain reverse sort is chronological.
        rsort($files);

        // A file written before rotation was switched on still holds history: read it last.
        if (is_file($this->pattern)) {
            $files[] = $this->pattern;
        }

        return $files;
    }

    /**
     * Deletes the dated files beyond the newest $maxFiles.
     *
     * Only files this class would itself have written are eligible: the name must match the
     * pattern *and* its date part must round-trip through the rotation format. The undated base
     * file is never touched — it may be the application's own log.
     *
     * @return list<string> the paths actually removed
     */
    public function prune(): array
    {
        if (!$this->rotates() || $this->maxFiles <= 0) {
            return [];
        }

        $dated = [];
        foreach ($this->all() as $path) {
            if (null !== $this->dateOf($path)) {
                $dated[] = $path;
            }
        }

        $removed = [];
        foreach (\array_slice($dated, $this->maxFiles) as $path) {
            if (@unlink($path)) {
                $removed[] = $path;
            }
        }

        return $removed;
    }

    /** `/x/logui.log` + `2026-10-13` → `/x/logui-2026-10-13.log` */
    private function dated(string $date): string
    {
        $extension = pathinfo($this->pattern, \PATHINFO_EXTENSION);
        $stem = '' === $extension
            ? $this->pattern
            : substr($this->pattern, 0, -(\strlen($extension) + 1));

        return $stem.'-'.$date.('' === $extension ? '' : '.'.$extension);
    }

    /**
     * The date a path encodes, or null when the name is not one of ours.
     *
     * The round-trip check is what makes deletion safe: `logui-backup.log` and
     * `logui-2026-13-99.log` both fail it, so neither can ever be unlinked.
     */
    private function dateOf(string $path): ?string
    {
        if (!$this->rotates()) {
            return null;
        }

        $format = self::FORMATS[$this->rotation];
        $prefix = $this->dated('');                       // `/x/logui-.log`
        $suffix = substr($prefix, strrpos($prefix, '-') + 1);
        $head = substr($prefix, 0, \strlen($prefix) - \strlen($suffix));

        if (!str_starts_with($path, $head) || ('' !== $suffix && !str_ends_with($path, $suffix))) {
            return null;
        }

        $date = substr($path, \strlen($head), \strlen($path) - \strlen($head) - \strlen($suffix));
        $parsed = \DateTimeImmutable::createFromFormat('!'.$format, $date);

        return false !== $parsed && $parsed->format($format) === $date ? $date : null;
    }
}

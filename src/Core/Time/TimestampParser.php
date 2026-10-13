<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Core\Time;

/**
 * Converts the raw date string captured by {@see \Aleblanc\LogUi\Core\Storage\PlainLogReader}
 * into an epoch timestamp in milliseconds.
 *
 * The conversion happens **at read time, line by line** — log files are never rewritten or
 * enriched on disk, so they keep their original size and format.
 *
 * Performance matters: this runs once per parsed line, on files that can reach several GB.
 * The implementation therefore avoids `DateTime`/`strtotime` on the hot path and computes the
 * epoch with integer arithmetic only (days-from-civil). Timezone offsets for formats that carry
 * none are resolved **once per calendar day** and memoized, which keeps DST transitions correct
 * without paying for a `DateTimeZone::getOffset()` call on every line.
 *
 * Recognised shapes (matching what the reader's regexes capture):
 *
 *   2026-10-13T12:34:56.789012+02:00   Monolog (ISO 8601, with or without micros/offset)
 *   2026-10-13 12:34:56                Monolog (older default format)
 *   2026/10/13 12:34:56                nginx error
 *   13/Oct/2026:12:34:56 +0200         nginx access
 *   13-Oct-2026 12:34:56 Europe/Paris  PHP error log
 *   12:34:56                           Symfony console — time only, see parseTimeOnly()
 */
final class TimestampParser
{
    private const MONTHS = [
        'Jan' => 1, 'Feb' => 2, 'Mar' => 3, 'Apr' => 4, 'May' => 5, 'Jun' => 6,
        'Jul' => 7, 'Aug' => 8, 'Sep' => 9, 'Oct' => 10, 'Nov' => 11, 'Dec' => 12,
    ];

    /**
     * Memoized UTC offsets (seconds) keyed by "Ymd" — recomputed at most once per day seen.
     * A null value flags a DST transition day, whose offset must be resolved per line.
     *
     * @var array<int,int|null>
     */
    private array $offsetCache = [];

    /** One-entry memo for the previous raw stamp (see parse()). */
    private ?string $lastRaw = null;

    private ?int $lastTs = null;

    private readonly \DateTimeZone $timezone;

    /**
     * @param string|null $timezone timezone assumed for formats that carry no offset
     *                              (defaults to the PHP process timezone)
     */
    public function __construct(?string $timezone = null)
    {
        $this->timezone = new \DateTimeZone($timezone ?? date_default_timezone_get());
    }

    /**
     * @return int|null epoch milliseconds, or null when the string carries no usable date
     *                  (unknown format, empty, or time-only — see parseTimeOnly())
     */
    public function parse(string $date): ?int
    {
        // A log file uses a single date format and usually emits several lines per second, so
        // consecutive raw stamps are frequently identical. Parsing is deterministic, hence a
        // one-entry memo is safe and removes most of the work on bursty files.
        if ($date === $this->lastRaw) {
            return $this->lastTs;
        }

        $len = \strlen($date);
        if ($len < 8) {
            return null;
        }

        $this->lastRaw = $date;

        return $this->lastTs = $this->parseUncached($date, $len);
    }

    private function parseUncached(string $date, int $len): ?int
    {
        // Dispatch on cheap character probes rather than running regexes.
        if ('-' === $date[4]) {
            return $this->parseIso($date, $len);           // 2026-10-13T… / 2026-10-13 …
        }
        if ('/' === $date[4]) {
            return $this->parseNginxError($date, $len);    // 2026/10/13 12:34:56
        }
        if ($len > 2 && '/' === $date[2]) {
            return $this->parseNginxAccess($date, $len);   // 13/Oct/2026:12:34:56 +0200
        }
        if ($len > 2 && '-' === $date[2]) {
            return $this->parsePhpError($date, $len);      // 13-Oct-2026 12:34:56 TZ
        }

        return null;
    }

    /** True when the string is a bare time of day ("12:34:56") and needs a date anchor. */
    public function isTimeOnly(string $date): bool
    {
        return 8 === \strlen($date) && ':' === $date[2] && ':' === $date[5];
    }

    /**
     * Resolves a time-only stamp ("12:34:56") against the day of $anchorMs.
     *
     * @param int $anchorMs epoch ms whose calendar day provides the missing date
     *
     * @return int|null epoch ms, or null when the string is not a valid time of day
     */
    public function parseTimeOnly(string $date, int $anchorMs): ?int
    {
        if (!$this->isTimeOnly($date)) {
            return null;
        }

        $h = (int) substr($date, 0, 2);
        $i = (int) substr($date, 3, 2);
        $s = (int) substr($date, 6, 2);
        if ($h > 23 || $i > 59 || $s > 60) {
            return null;
        }

        // Start of the anchor's local day, then add the time of day.
        $anchorSec = intdiv($anchorMs, 1000);
        [$y, $m, $d] = $this->civilFromLocal($anchorSec);
        $dayStart = $this->epoch($y, $m, $d, 0, 0, 0, $this->offsetFor($y, $m, $d, 0, 0, 0));

        return ($dayStart + $h * 3600 + $i * 60 + $s) * 1000;
    }

    // ---------------------------------------------------------------- parsers

    /*
     * Implementation note (measured, not assumed): on interpreted PHP a function call costs
     * ~60 ns, so a parser split into int2()/int4() helpers spent more time on call overhead
     * than on arithmetic — it benchmarked *slower* than strtotime(). The digit extraction is
     * therefore inlined and uses `(int) substr(...)`, which is one C-level call instead of
     * several interpreted operations. Only `epoch()` remains a call, to avoid duplicating the
     * days-from-civil algorithm four times.
     */

    /** `2026-10-13T12:34:56.789012+02:00`, `2026-10-13 12:34:56`, trailing `Z` supported. */
    private function parseIso(string $s, int $len): ?int
    {
        if ($len < 19 || '-' !== $s[7]) {
            return null;
        }

        $y = (int) substr($s, 0, 4);
        $mo = (int) substr($s, 5, 2);
        $d = (int) substr($s, 8, 2);
        if (0 === $y || $mo < 1 || $mo > 12 || $d < 1 || $d > 31) {
            return null;
        }

        $h = (int) substr($s, 11, 2);
        $mi = (int) substr($s, 14, 2);
        $sec = (int) substr($s, 17, 2);

        $pos = 19;
        $ms = 0;
        if ($pos < $len && '.' === $s[$pos]) {
            ++$pos;
            // strspn counts the fractional digits in one C call — a PHP-level loop here
            // measured as the single most expensive step of the whole parser.
            $digits = strspn($s, '0123456789', $pos);
            // Keep milliseconds only: the first 3 digits, right-padded when shorter.
            $ms = $digits >= 3
                ? (int) substr($s, $pos, 3)
                : (int) str_pad(substr($s, $pos, $digits), 3, '0');
            $pos += $digits;
        }

        $offset = $this->readOffset($s, $pos, $len) ?? $this->offsetFor($y, $mo, $d, $h, $mi, $sec);

        return $this->epoch($y, $mo, $d, $h, $mi, $sec, $offset) * 1000 + $ms;
    }

    /** `2026/10/13 12:34:56` (nginx error log — local time, no offset). */
    private function parseNginxError(string $s, int $len): ?int
    {
        if ($len < 19 || '/' !== $s[7]) {
            return null;
        }

        $y = (int) substr($s, 0, 4);
        $mo = (int) substr($s, 5, 2);
        $d = (int) substr($s, 8, 2);
        if (0 === $y || $mo < 1 || $mo > 12) {
            return null;
        }

        $h = (int) substr($s, 11, 2);
        $mi = (int) substr($s, 14, 2);
        $sec = (int) substr($s, 17, 2);

        return $this->epoch($y, $mo, $d, $h, $mi, $sec, $this->offsetFor($y, $mo, $d, $h, $mi, $sec)) * 1000;
    }

    /** `13/Oct/2026:12:34:56 +0200` (nginx access log — offset always present). */
    private function parseNginxAccess(string $s, int $len): ?int
    {
        if ($len < 20 || '/' !== $s[6]) {
            return null;
        }

        $mo = self::MONTHS[substr($s, 3, 3)] ?? 0;
        if (0 === $mo) {
            return null;
        }

        $d = (int) substr($s, 0, 2);
        $y = (int) substr($s, 7, 4);
        $h = (int) substr($s, 12, 2);
        $mi = (int) substr($s, 15, 2);
        $sec = (int) substr($s, 18, 2);

        $offset = ($len > 21 ? $this->readOffset($s, 21, $len) : null)
            ?? $this->offsetFor($y, $mo, $d, $h, $mi, $sec);

        return $this->epoch($y, $mo, $d, $h, $mi, $sec, $offset) * 1000;
    }

    /** `13-Oct-2026 12:34:56 Europe/Paris` (PHP error log — named zone ignored: local assumed). */
    private function parsePhpError(string $s, int $len): ?int
    {
        if ($len < 20 || '-' !== $s[6]) {
            return null;
        }

        $mo = self::MONTHS[substr($s, 3, 3)] ?? 0;
        if (0 === $mo) {
            return null;
        }

        $d = (int) substr($s, 0, 2);
        $y = (int) substr($s, 7, 4);
        $h = (int) substr($s, 12, 2);
        $mi = (int) substr($s, 15, 2);
        $sec = (int) substr($s, 18, 2);

        return $this->epoch($y, $mo, $d, $h, $mi, $sec, $this->offsetFor($y, $mo, $d, $h, $mi, $sec)) * 1000;
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Reads a trailing timezone designator at $pos: `Z`, `+02:00`, `-0500`.
     *
     * @return int|null offset in seconds, or null when absent/unrecognised
     */
    private function readOffset(string $s, int $pos, int $len): ?int
    {
        while ($pos < $len && ' ' === $s[$pos]) {
            ++$pos;
        }
        if ($pos >= $len) {
            return null;
        }

        $c = $s[$pos];
        if ('Z' === $c || 'z' === $c) {
            return 0;
        }
        if (('+' !== $c && '-' !== $c) || $pos + 4 >= $len) {
            return null;
        }

        $h = (int) substr($s, $pos + 1, 2);
        $mi = ':' === $s[$pos + 3] ? (int) substr($s, $pos + 4, 2) : (int) substr($s, $pos + 3, 2);
        $offset = $h * 3600 + $mi * 60;

        return '-' === $c ? -$offset : $offset;
    }

    /**
     * UTC offset (seconds) for a local date/time.
     *
     * Fast path: on an ordinary day the offset is constant, so it is resolved once and memoized —
     * one `DateTimeZone` lookup per calendar day seen, whatever the number of lines.
     *
     * On the two DST transition days per year the offset changes mid-day; those days are flagged
     * in the cache (`null`) and resolved exactly, per line. Using a single whole-day offset there
     * would shift every record before the switch by an hour.
     */
    private function offsetFor(int $y, int $m, int $d, int $h = 12, int $i = 0, int $s = 0): int
    {
        $key = $y * 10000 + $m * 100 + $d;

        if (!\array_key_exists($key, $this->offsetCache)) {
            $dayStart = new \DateTimeImmutable(sprintf('%04d-%02d-%02d 00:00:00', $y, $m, $d), $this->timezone);
            $dayEnd = new \DateTimeImmutable(sprintf('%04d-%02d-%02d 23:59:59', $y, $m, $d), $this->timezone);
            $startOffset = $dayStart->getOffset();

            // null marks a transition day: the offset is not constant, resolve per line below.
            $this->offsetCache[$key] = $startOffset === $dayEnd->getOffset() ? $startOffset : null;
        }

        $cached = $this->offsetCache[$key];
        if (null !== $cached) {
            return $cached;
        }

        // Transition day: the exact local time decides which side of the switch we are on.
        // A non-existent local time (spring forward) is normalised by DateTimeImmutable.
        return (new \DateTimeImmutable(
            sprintf('%04d-%02d-%02d %02d:%02d:%02d', $y, $m, $d, $h, $i, $s),
            $this->timezone,
        ))->getOffset();
    }

    /**
     * Epoch seconds from a civil date/time and its UTC offset.
     * Days-from-civil (Howard Hinnant) — pure integer arithmetic, no calendar object.
     */
    private function epoch(int $y, int $m, int $d, int $h, int $i, int $s, int $offset): int
    {
        $yy = $y - ($m <= 2 ? 1 : 0);
        $era = intdiv($yy >= 0 ? $yy : $yy - 399, 400);
        $yoe = $yy - $era * 400;                                  // [0, 399]
        $doy = intdiv(153 * ($m + ($m > 2 ? -3 : 9)) + 2, 5) + $d - 1;
        $doe = $yoe * 365 + intdiv($yoe, 4) - intdiv($yoe, 100) + $doy;
        $days = $era * 146097 + $doe - 719468;

        return $days * 86400 + $h * 3600 + $i * 60 + $s - $offset;
    }

    /**
     * Local calendar date for an epoch second.
     *
     * @return array{int,int,int} [year, month, day]
     */
    private function civilFromLocal(int $epochSec): array
    {
        // Only reached for time-only logs (console), which are small by nature.
        $dt = (new \DateTimeImmutable('@'.$epochSec))->setTimezone($this->timezone);

        return [(int) $dt->format('Y'), (int) $dt->format('n'), (int) $dt->format('j')];
    }
}

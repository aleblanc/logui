<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Core\Storage;

use Aleblanc\LogUi\Core\Model\LogLevel;
use Aleblanc\LogUi\Core\Time\TimestampParser;

/**
 * Parses plain log files into structured rows, recognising several common formats:
 *  - Monolog LineFormatter:   [date] channel.LEVEL: message {context} {extra}   (+ multi-line traces)
 *  - Monolog JSON family:     one JSON object per line — JsonFormatter, Logmatic, Logstash
 *                             (@timestamp/level), GoogleCloudLogging (severity/time) all covered.
 *  - Symfony ConsoleHandler:  HH:MM:SS LEVEL [channel] message   (console command logs)
 *  - Monolog Syslog/Gelf etc: SyslogFormatter extends LineFormatter (handled above); network/DB/
 *                             browser formatters (Gelf, Fluentd, Elastica, Mongo, ChromePHP…) don't
 *                             write plain log files, so they're out of scope for this viewer.
 *  - nginx access (combined): ip - - [date] "METHOD path HTTP/x" status bytes ...
 *  - nginx/PHP error:         2026/06/10 12:40:01 [error] ... | [date] LEVEL: message
 *  - LogUI telemetry:         LOGUI@{json} — one captured request/command, exposed as a row of
 *                             type `request` or `command` carrying its header (see TelemetryHeader).
 *  - anything else:           kept verbatim as a raw row (never dropped).
 *
 * ANSI colour escape sequences (common in captured console output) are stripped from every line.
 *
 * Monolog limitation: a message that itself embeds a JSON blob may have that fragment
 * misattributed to the context field. Well-formed Monolog output parses correctly.
 *
 * @phpstan-type Row array{date:string,ts:?int,ts_inferred:bool,file:string,channel:string,level:string,message:string,context:?string,extra:?string,type:string,profile:?array<string,mixed>,offset:int}
 * @phpstan-type Tick array{ts:?int,level:string,channel:string,type:string,profile:?array<string,mixed>,message:?string,weight:int}
 */
final class PlainLogReader
{
    /**
     * Timestamps are derived from each line as it is read — log files are never rewritten,
     * so they keep their original size and format on disk.
     */
    private readonly TimestampParser $timestamps;

    public function __construct(?TimestampParser $timestamps = null)
    {
        $this->timestamps = $timestamps ?? new TimestampParser();
    }

    /** Default tail window: only the last ~2 MB of a file is read by readTail(). */
    public const DEFAULT_TAIL_BYTES = 2_000_000;

    /** Default budget for a ranged read — the range itself bounds the work, this is a safety net. */
    public const DEFAULT_RANGE_BYTES = 8_000_000;

    /**
     * How many consecutive lines past the end of the range are tolerated before stopping.
     * Guards against files that are not perfectly ordered (concatenations, clock steps).
     */
    private const OUT_OF_ORDER_TOLERANCE = 50;

    /** Monolog line header alone — what counting needs, a third of the cost of the full regex. */
    private const MONOLOG_HEADER = '/^\[([^\]]+)\] ([\w.\-]+)\.([A-Z]+): /';

    /** Info/debug records parsed in full before {@see scanRange()} starts sampling them for patterns. */
    private const PATTERN_EXACT_RECORDS = 2_000;

    /** Sampling granularity of {@see scanRange()}: blocks are read whole or skipped whole. */
    public const SAMPLE_BLOCK_BYTES = 256 * 1024;

    private const MONOLOG = '/^\[(?<date>[^\]]+)\] (?<channel>[\w.\-]+)\.(?<level>[A-Z]+): (?<message>.*?)(?: (?<context>[\{\[].*?[\}\]]))?(?: (?<extra>[\{\[].*?[\}\]]))?\s*$/';
    // Symfony ConsoleHandler: "HH:MM:SS LEVEL [channel] message". Levels are listed explicitly so
    // console table rows / status lines never get misread as log entries.
    private const CONSOLE = '/^(?<date>\d{2}:\d{2}:\d{2}) (?<level>DEBUG|INFO|NOTICE|WARNING|ERROR|CRITICAL|ALERT|EMERGENCY)\s+\[(?<channel>[\w.\-]+)\] (?<message>.*)$/';
    private const NGINX_ACCESS = '/^\S+ \S+ \S+ \[(?<date>[^\]]+)\] "(?<req>[^"]*)" (?<status>\d{3}) /';
    private const NGINX_ERROR = '#^(?<date>\d{4}/\d{2}/\d{2} \d{2}:\d{2}:\d{2}) \[(?<level>[a-z]+)\] (?<message>.*)$#';
    private const PHP_ERROR = '/^\[(?<date>[^\]]+)\] (?<level>[A-Z]+): (?<message>.*)$/';

    /** nginx/PHP error level word → our canonical level. */
    private const ERROR_LEVELS = [
        'emerg' => 'emergency', 'alert' => 'alert', 'crit' => 'critical', 'error' => 'error',
        'warn' => 'warning', 'notice' => 'notice', 'info' => 'info', 'debug' => 'debug',
    ];

    /** Monolog numeric level → canonical name (for JSON formatters that emit only the integer). */
    private const MONOLOG_LEVELS = [
        100 => 'debug', 200 => 'info', 250 => 'notice', 300 => 'warning',
        400 => 'error', 500 => 'critical', 550 => 'alert', 600 => 'emergency',
    ];

    /**
     * @return \Generator<int,Row>
     */
    public function read(string $path): \Generator
    {
        if (!is_file($path)) {
            return;
        }

        $handle = fopen($path, 'rb');
        if (false === $handle) {
            return;
        }

        try {
            yield from $this->parse($handle, $this->anchorFor($path));
        } finally {
            fclose($handle);
        }
    }

    /**
     * Parses only the last $maxBytes of the file — safe on multi-GB logs (a 5 GB file costs ~2 MB).
     * If the window starts mid-file, the first (partial) line is discarded.
     *
     * @return \Generator<int,Row>
     */
    public function readTail(string $path, int $maxBytes = self::DEFAULT_TAIL_BYTES): \Generator
    {
        if (!is_file($path)) {
            return;
        }

        $handle = fopen($path, 'rb');
        if (false === $handle) {
            return;
        }

        try {
            $size = fstat($handle)['size'] ?? 0;
            if ($size > $maxBytes) {
                fseek($handle, $size - $maxBytes);
                fgets($handle); // drop the partial first line of the window
            }
            yield from $this->parse($handle, $this->anchorFor($path));
        } finally {
            fclose($handle);
        }
    }

    /**
     * Reads the lines appended after a byte offset — the primitive behind the live tail.
     *
     * $offset is expected to be a boundary left by a previous call (typically the file size at
     * that moment), so no partial line is produced. Only the new bytes are touched: following a
     * multi-GB file is as cheap as following an empty one.
     *
     * @return \Generator<int,Row>
     */
    public function readFrom(string $path, int $offset): \Generator
    {
        if (!is_file($path)) {
            return;
        }

        $handle = fopen($path, 'rb');
        if (false === $handle) {
            return;
        }

        try {
            $size = fstat($handle)['size'] ?? 0;
            if ($offset >= $size) {
                return;
            }
            if ($offset > 0 && -1 === fseek($handle, $offset)) {
                return;
            }

            yield from $this->parse($handle, $this->anchorFor($path));
        } finally {
            fclose($handle);
        }
    }

    /**
     * Reads only the lines whose timestamp falls in [$fromMs, $toMs].
     *
     * The start of the range is located by binary search on byte offsets ({@see LogSeeker}), so
     * a narrow window inside a multi-GB file costs a handful of seeks instead of a full scan.
     * Reading stops as soon as the range is left, and a byte budget caps the work in any case.
     *
     * Falls back to a plain tail read when the file is too small to be worth searching, or when
     * it holds no datable line at all (the seeker cannot orient itself there).
     *
     * Lines that carry no date of their own are yielded when they fall inside the scanned window:
     * positionally they belong to the range, and dropping them would silently hide raw log output.
     *
     * @param (callable(float,int,int,int,bool):void)|null $report receives, once the read ends, the time
     *                                                         the binary search took (ms), the bytes
     *                                                         scanned after it, the rows yielded, the
     *                                                         file size, and whether $maxBytes cut
     *                                                         the read short
     *
     * @return \Generator<int,Row>
     */
    public function readRange(string $path, int $fromMs, int $toMs, int $maxBytes = self::DEFAULT_RANGE_BYTES, ?callable $report = null): \Generator
    {
        if (!is_file($path) || $fromMs > $toMs) {
            return;
        }

        $handle = fopen($path, 'rb');
        if (false === $handle) {
            return;
        }

        try {
            $size = fstat($handle)['size'] ?? 0;
            $anchor = $this->anchorFor($path);

            $seeker = new LogSeeker(fn (string $line): ?int => $this->lineTimestamp($line, $anchor));
            $seekStarted = hrtime(true);
            $start = $seeker->offsetFor($handle, $size, $fromMs);
            $seekMs = (hrtime(true) - $seekStarted) / 1e6;
            $yielded = 0;
            $capped = false;

            if (-1 === fseek($handle, $start)) {
                return;
            }
            if ($start > 0) {
                fgets($handle); // drop the partial line the offset landed in
            }

            $budget = $maxBytes;
            $beyond = 0;
            $inRange = false;

            foreach ($this->parse($handle, $anchor) as $row) {
                $ts = $row['ts'];

                if (null !== $ts) {
                    if ($ts > $toMs) {
                        // Tolerate a few out-of-order lines before concluding the range is over.
                        if (++$beyond > self::OUT_OF_ORDER_TOLERANCE) {
                            return;
                        }
                        continue;
                    }
                    $beyond = 0;

                    if ($ts < $fromMs) {
                        continue;
                    }

                    $inRange = true;
                } elseif (!$inRange) {
                    // Seeking lands mid-record, so the scan can start on an orphaned continuation
                    // line (a stack-trace frame whose header is behind us). Such leading fragments
                    // are an artefact of the seek, not log content: drop them until the first
                    // record of the range is reached. Undatable lines *inside* the range are kept.
                    continue;
                }

                yield $row;
                ++$yielded;

                $budget -= \strlen($row['message']) + 64;
                if ($budget <= 0) {
                    $capped = true;

                    return;
                }
            }
        } finally {
            if (null !== $report && isset($seekMs, $start)) {
                $end = ftell($handle);
                $report($seekMs, max(0, (false === $end ? $start : $end) - $start), $yielded ?? 0, $size ?? 0, $capped ?? false);
            }
            fclose($handle);
        }
    }

    /**
     * The records of [$fromMs, $toMs] reduced to what a histogram counts — the fast path behind
     * {@see \Aleblanc\LogUi\Core\Query\LogExplorer::histogram()} when no text search is active.
     *
     * Monolog lines are only read up to their level: no message/context/extra split, no context
     * JSON. The full parse runs on every warning and above, on the first
     * {@see PATTERN_EXACT_RECORDS} info/debug records, then on one in $patternEvery — enough for the
     * patterns panel, whose counts are weighted accordingly (`weight`). Other formats (JSON, nginx, telemetry…) are parsed in full as usual.
     *
     * With $fraction < 1 only that share of the file is read: evenly spread blocks of
     * {@see SAMPLE_BLOCK_BYTES}, read whole or skipped whole. The caller scales the counts back up.
     *
     * Continuation lines are not records and yield nothing; a record whose first line mentions one
     * of $excluding is skipped.
     *
     * @param list<string>                                     $excluding
     * @param (callable(float,int,int,int,bool):void)|null     $report    as for {@see readRange()}
     *
     * @return \Generator<int,Tick>
     */
    public function scanRange(string $path, int $fromMs, int $toMs, float $fraction = 1.0, int $patternEvery = 8, array $excluding = [], ?callable $report = null): \Generator
    {
        if (!is_file($path) || $fromMs > $toMs) {
            return;
        }

        $handle = fopen($path, 'rb');
        if (false === $handle) {
            return;
        }

        $read = 0;
        $yielded = 0;
        $seekMs = 0.0;
        $size = 0;

        try {
            $size = fstat($handle)['size'] ?? 0;
            $anchor = $this->anchorFor($path);

            $seekStarted = hrtime(true);
            $start = (new LogSeeker(fn (string $line): ?int => $this->lineTimestamp($line, $anchor)))->offsetFor($handle, $size, $fromMs);
            $seekMs = (hrtime(true) - $seekStarted) / 1e6;

            $beyond = 0;
            $inRange = false;
            $sampled = 0;
            // Levels whose records are always parsed in full: rare, and the ones worth naming.
            $loud = [];
            foreach (LogLevel::all() as $name) {
                if (LogLevel::severity($name) >= LogLevel::severity('warning')) {
                    $loud[strtoupper($name)] = true;
                }
            }
            $sentinel = TelemetryReader::SENTINEL;
            $sentinelLength = \strlen($sentinel);
            // Reading the whole rest of the file needs no position check per line.
            $bounded = $fraction < 1.0;

            foreach ($this->segments($start, $size, $fraction) as [$segStart, $segEnd]) {
                if (!$this->seekLineAt($handle, $segStart)) {
                    break;
                }
                $allowContinuation = false;

                while (true) {
                    if ($bounded) {
                        $lineStart = ftell($handle);
                        if (false === $lineStart || $lineStart >= $segEnd) {
                            break;
                        }
                    }
                    $raw = fgets($handle);
                    if (false === $raw) {
                        break;
                    }
                    $read += \strlen($raw);

                    $line = rtrim($raw, "\r\n");
                    if (str_contains($line, "\e")) {
                        $line = preg_replace('/\e\[[0-9;]*m/', '', $line) ?? $line;
                    }
                    if ('' === $line) {
                        continue;
                    }

                    $tick = null;
                    if ('[' === $line[0] && preg_match(self::MONOLOG_HEADER, $line, $m)
                        && 0 !== substr_compare($line, $sentinel, \strlen($m[0]), $sentinelLength)) {
                        $message = null;
                        $weight = 1;
                        if (isset($loud[$m[3]])) {
                            $message = $this->classify($line)['row']['message'] ?? null;
                        } elseif (++$sampled <= self::PATTERN_EXACT_RECORDS) {
                            // A small file is counted exactly; sampling only starts past this many.
                            $message = $this->classify($line)['row']['message'] ?? null;
                        } elseif (0 === $sampled % $patternEvery) {
                            $message = $this->classify($line)['row']['message'] ?? null;
                            $weight = $patternEvery;
                        }
                        $level = strtolower($m[3]);
                        $tick = ['ts' => $this->timestamps->parse($m[1]) ?? $this->timestampOf($m[1], $anchor), 'level' => $level, 'channel' => $m[2], 'type' => 'log', 'profile' => null, 'message' => $message, 'weight' => $weight];
                        $allowContinuation = true;
                    } elseif (null !== ($classified = $this->classify($line))) {
                        $row = $this->stamp($classified['row'], $anchor);
                        $tick = ['ts' => $row['ts'], 'level' => $row['level'], 'channel' => $row['channel'], 'type' => $row['type'], 'profile' => $row['profile'], 'message' => $row['message'], 'weight' => 1];
                        $allowContinuation = $classified['monolog'];
                    } elseif ($allowContinuation) {
                        continue; // a stack-trace frame: part of the record above, not a record
                    } else {
                        $tick = ['ts' => null, 'level' => '', 'channel' => '', 'type' => 'log', 'profile' => null, 'message' => $line, 'weight' => 1];
                    }

                    $ts = $tick['ts'];
                    if (null !== $ts) {
                        if ($ts > $toMs) {
                            if (++$beyond > self::OUT_OF_ORDER_TOLERANCE) {
                                return;
                            }
                            continue;
                        }
                        $beyond = 0;
                        if ($ts < $fromMs) {
                            continue;
                        }
                        $inRange = true;
                    } elseif (!$inRange) {
                        continue; // a leading fragment left by the seek (see readRange())
                    }

                    foreach ($excluding as $needle) {
                        if (str_contains($line, $needle)) {
                            continue 2;
                        }
                    }

                    ++$yielded;
                    yield $tick;
                }
            }
        } finally {
            if (null !== $report) {
                $report($seekMs, $read, $yielded, $size, false);
            }
            fclose($handle);
        }
    }

    /**
     * Byte ranges to read from $start: the whole rest of the file, or — sampling — every block whose
     * turn comes up when $fraction of them are spread evenly.
     *
     * @return \Generator<int,array{int,int}>
     */
    private function segments(int $start, int $size, float $fraction): \Generator
    {
        if ($fraction >= 1.0) {
            yield [$start, $size];

            return;
        }

        $blocks = (int) ceil(($size - $start) / self::SAMPLE_BLOCK_BYTES);
        for ($i = 0; $i < $blocks; ++$i) {
            // Bresenham-style: block i is taken when the running share crosses an integer.
            if ((int) floor(($i + 1) * $fraction) > (int) floor($i * $fraction)) {
                $from = $start + $i * self::SAMPLE_BLOCK_BYTES;
                yield [$from, min($size, $from + self::SAMPLE_BLOCK_BYTES)];
            }
        }
    }

    /**
     * Positions the handle on the first line that *starts* at or after $offset. A line that began
     * before $offset belongs to the previous segment, which read it whole.
     *
     * @param resource $handle
     */
    private function seekLineAt($handle, int $offset): bool
    {
        if (0 === $offset) {
            return 0 === fseek($handle, 0);
        }
        if (-1 === fseek($handle, $offset - 1)) {
            return false;
        }
        $prev = fgetc($handle);
        if ("\n" !== $prev) {
            fgets($handle);
        }

        return true;
    }

    /**
     * The first timestamp of a file — enough to tell whether it lies wholly inside a window.
     * Reads at most a few hundred records from the top.
     */
    public function firstTimestamp(string $path): ?int
    {
        $seen = 0;
        foreach ($this->read($path) as $row) {
            if (null !== $row['ts']) {
                return $row['ts'];
            }
            if (++$seen >= 200) {
                break;
            }
        }

        return null;
    }

    /** Epoch ms of a raw date string — {@see stamp()} without building a row. */
    private function timestampOf(string $date, int $anchorMs): ?int
    {
        return $this->stamp(['date' => $date, 'ts' => null, 'ts_inferred' => false, 'file' => '', 'channel' => '', 'level' => '', 'message' => '', 'context' => null, 'extra' => null, 'type' => 'log', 'profile' => null, 'offset' => 0], $anchorMs)['ts'];
    }

    /**
     * Records whose first line begins at a byte offset in `[from, to)`.
     *
     * Used to read the lines around a specific record (context lines): the offset is a plain byte
     * position, so no timestamp reasoning is involved. When `from > 0` the seek lands mid-line, so
     * that partial line is dropped before parsing.
     *
     * @return \Generator<int,Row>
     */
    public function readBetween(string $path, int $from, int $to): \Generator
    {
        if (!is_file($path) || $from >= $to) {
            return;
        }

        $handle = fopen($path, 'rb');
        if (false === $handle) {
            return;
        }

        try {
            if ($from > 0) {
                if (-1 === fseek($handle, $from - 1)) {
                    return;
                }
                // Drop the partial line only when the offset lands mid-line. An offset that is
                // exactly a record boundary (the "after" window starts on the pivot) must keep its
                // first line — reading the char before it tells the two cases apart.
                $prev = fgetc($handle);
                if ("\n" !== $prev && "\r" !== $prev) {
                    fgets($handle);
                }
            }

            yield from $this->parse($handle, $this->anchorFor($path), $to);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Epoch-ms of a single raw line, used by the seeker to orient itself.
     * Returns null for continuation lines and anything undatable.
     */
    private function lineTimestamp(string $line, int $anchorMs): ?int
    {
        $classified = $this->classify($line);

        return null === $classified ? null : $this->stamp($classified['row'], $anchorMs)['ts'];
    }

    /**
     * @param resource $handle
     * @param int|null $stopAt stop before any record whose first line begins at or past this byte
     *                         offset — used to read a bounded window ending on a record boundary
     *
     * @return \Generator<int,Row>
     */
    private function parse($handle, int $anchorMs, ?int $stopAt = null): \Generator
    {
        /** @var Row|null $current */
        $current = null;
        $allowContinuation = false;

        while (true) {
            $lineStart = ftell($handle);
            $raw = fgets($handle);
            if (false === $raw) {
                break;
            }
            // A new record starting at or past the stop boundary ends the window; the record that
            // was being built (which began before it) is still yielded after the loop.
            if (null !== $stopAt && false !== $lineStart && $lineStart >= $stopAt) {
                break;
            }
            $offset = false === $lineStart ? 0 : $lineStart;

            $line = rtrim($raw, "\r\n");
            // Strip ANSI colour escapes (captured console output is full of them) — only when
            // there is one: the check is a memchr, the regex is not.
            if (str_contains($line, "\e")) {
                $line = preg_replace('/\e\[[0-9;]*m/', '', $line) ?? $line;
            }
            if ('' === $line) {
                continue;
            }

            $classified = $this->classify($line);

            if (null !== $classified) {
                if (null !== $current) {
                    yield $current;
                }
                $current = $this->stamp($classified['row'], $anchorMs);
                $current['offset'] = $offset;
                $allowContinuation = $classified['monolog'];

                continue;
            }

            // Unrecognised line: a Monolog stack-trace continuation, or a standalone raw line.
            if (null !== $current && $allowContinuation) {
                $current['message'] .= "\n".$line;

                continue;
            }

            if (null !== $current) {
                yield $current;
            }
            $current = $this->rawRow($line);
            $current['offset'] = $offset;
            $allowContinuation = false;
        }

        if (null !== $current) {
            yield $current;
        }
    }

    /**
     * @return array{monolog:bool,row:Row}|null null when the line doesn't start a recognised entry
     */
    private function classify(string $line): ?array
    {
        if (str_starts_with($line, TelemetryReader::SENTINEL) && null !== ($telemetry = $this->classifyTelemetry(substr($line, \strlen(TelemetryReader::SENTINEL))))) {
            return $telemetry;
        }

        if (str_starts_with($line, '{') && null !== ($json = $this->classifyJson($line))) {
            return $json;
        }

        if (preg_match(self::MONOLOG, $line, $m)) {
            // Older installs logged the telemetry through Monolog: the profile is the message.
            if (str_starts_with($m['message'], TelemetryReader::SENTINEL)
                && null !== ($telemetry = $this->classifyTelemetry(substr($m['message'], \strlen(TelemetryReader::SENTINEL))))) {
                return $telemetry;
            }

            return ['monolog' => true, 'row' => [
                'date' => $m['date'],
                'ts' => null,
                'ts_inferred' => false,
                'file' => '',
                'channel' => $m['channel'],
                'level' => strtolower($m['level']),
                'message' => $m['message'],
                'context' => ('' !== ($m['context'] ?? '')) ? $m['context'] : null,
                'extra' => ('' !== ($m['extra'] ?? '')) ? $m['extra'] : null,
                'type' => 'log',
                'profile' => null,
                'offset' => 0,
            ]];
        }

        if (preg_match(self::CONSOLE, $line, $m)) {
            return ['monolog' => false, 'row' => [
                'date' => $m['date'],
                'ts' => null,
                'ts_inferred' => false,
                'file' => '',
                'channel' => $m['channel'],
                'level' => strtolower($m['level']),
                'message' => $m['message'],
                'context' => null,
                'extra' => null,
                'type' => 'log',
                'profile' => null,
                'offset' => 0,
            ]];
        }

        if (preg_match(self::NGINX_ACCESS, $line, $m)) {
            return ['monolog' => false, 'row' => [
                'date' => $m['date'],
                'ts' => null,
                'ts_inferred' => false,
                'file' => '',
                'channel' => 'access',
                'level' => $this->statusLevel((int) $m['status']),
                'message' => $m['req'].' — '.$m['status'],
                'context' => null,
                'extra' => null,
                'type' => 'log',
                'profile' => null,
                'offset' => 0,
            ]];
        }

        if (preg_match(self::NGINX_ERROR, $line, $m)) {
            return ['monolog' => false, 'row' => [
                'date' => $m['date'],
                'ts' => null,
                'ts_inferred' => false,
                'file' => '',
                'channel' => 'nginx',
                'level' => self::ERROR_LEVELS[$m['level']] ?? 'error',
                'message' => $m['message'],
                'context' => null,
                'extra' => null,
                'type' => 'log',
                'profile' => null,
                'offset' => 0,
            ]];
        }

        if (preg_match(self::PHP_ERROR, $line, $m)) {
            return ['monolog' => false, 'row' => [
                'date' => $m['date'],
                'ts' => null,
                'ts_inferred' => false,
                'file' => '',
                'channel' => 'php',
                'level' => self::ERROR_LEVELS[strtolower($m['level'])] ?? 'error',
                'message' => $m['message'],
                'context' => null,
                'extra' => null,
                'type' => 'log',
                'profile' => null,
                'offset' => 0,
            ]];
        }

        return null;
    }

    /**
     * LogUI's own telemetry: one captured request or command, shown as a row of its own type.
     *
     * Only the header is decoded here (see TelemetryHeader) — the records stay inside `context`
     * as text, which keeps the search box and `| json` able to see them while the list stays
     * cheap. The full profile is decoded on demand when a row is expanded.
     *
     * @param string $payload the text after the sentinel
     *
     * @return array{monolog:bool,row:Row}|null
     */
    private function classifyTelemetry(string $payload): ?array
    {
        $header = TelemetryHeader::extract($payload);
        if (null === $header) {
            return null;
        }

        return ['monolog' => false, 'row' => [
            'date' => $header['at'],
            'ts' => null,
            'ts_inferred' => false,
            'file' => '',
            'channel' => '',
            'level' => TelemetryHeader::level($header),
            'message' => $header['label'],
            'context' => $payload,
            'extra' => null,
            'type' => TelemetryHeader::rowType($header['type']),
            'profile' => $header,
            'offset' => 0,
        ]];
    }

    /**
     * Monolog JsonFormatter line (one JSON object per record). Returns null when the line is some
     * other JSON, so it falls through to a raw row rather than being mis-parsed.
     *
     * @return array{monolog:bool,row:Row}|null
     */
    private function classifyJson(string $line): ?array
    {
        $data = json_decode($line, true);
        if (!\is_array($data)) {
            return null;
        }
        if (!isset($data['message'], $data['channel'])) {
            return $this->classifyJsonEvent($data, $line);
        }

        $level = $this->jsonLevel($data);
        if (null === $level) {
            return null; // has message+channel but no level marker → not a Monolog record, keep raw
        }

        return ['monolog' => false, 'row' => [
            'date' => $this->jsonDate($data),
            'ts' => null,
            'ts_inferred' => false,
            'file' => '',
            'channel' => \is_string($data['channel']) ? $data['channel'] : '',
            'level' => $level,
            'message' => \is_string($data['message']) ? $data['message'] : (string) json_encode($data['message']),
            'context' => $this->encodePart($data['context'] ?? null),
            'extra' => $this->encodePart($data['extra'] ?? null),
            'type' => 'log',
            'profile' => null,
            'offset' => 0,
        ]];
    }

    /**
     * Any other JSON line that carries a date (`ts`, `time`, `datetime`, `@timestamp`…): an
     * application's own event log, such as `{"ts":1730000000,"source":"eth0","rx":123}`.
     *
     * Kept whole as the message so search and `| json` still see every field, but dated — so it
     * lands on the histogram and the binary search can find its way through the file. Lines with
     * no date stay raw: nothing would place them in time.
     *
     * @param array<array-key,mixed> $data
     *
     * @return array{monolog:bool,row:Row}|null
     */
    private function classifyJsonEvent(array $data, string $line): ?array
    {
        $date = $this->jsonDate($data);
        if ('' === $date) {
            return null;
        }

        $channel = '';
        foreach (['channel', 'source', 'logger', 'name'] as $key) {
            if (isset($data[$key]) && \is_string($data[$key])) {
                $channel = $data[$key];
                break;
            }
        }

        return ['monolog' => false, 'row' => [
            'date' => $date,
            'ts' => null,
            'ts_inferred' => false,
            'file' => '',
            'channel' => $channel,
            'level' => $this->jsonLevel($data) ?? 'info',
            'message' => isset($data['message']) && \is_string($data['message']) ? $data['message'] : $line,
            'context' => null,
            'extra' => null,
            'type' => 'log',
            'profile' => null,
            'offset' => 0,
        ]];
    }

    /**
     * Resolve the canonical level across JSON variants: level_name (JsonFormatter/Logmatic),
     * severity (GoogleCloudLogging), level-as-name (Logstash), or a numeric level/monolog_level.
     *
     * @param array<array-key,mixed> $data
     */
    private function jsonLevel(array $data): ?string
    {
        foreach (['level_name', 'severity', 'level'] as $key) {
            if (isset($data[$key]) && \is_string($data[$key])) {
                return strtolower($data[$key]);
            }
        }

        foreach (['level', 'monolog_level'] as $key) {
            if (isset($data[$key]) && \is_int($data[$key]) && isset(self::MONOLOG_LEVELS[$data[$key]])) {
                return self::MONOLOG_LEVELS[$data[$key]];
            }
        }

        return null;
    }

    /**
     * Resolve the timestamp across variants: datetime (JsonFormatter), @timestamp (Logstash),
     * time (GoogleCloudLogging), timestamp / ts (application event logs; numeric epochs accepted).
     *
     * @param array<array-key,mixed> $data
     */
    private function jsonDate(array $data): string
    {
        foreach (['datetime', '@timestamp', 'time', 'timestamp', 'ts'] as $key) {
            if (!isset($data[$key])) {
                continue;
            }
            $value = $data[$key];
            if (\is_string($value) && !is_numeric($value)) {
                return $value;
            }
            if (is_numeric($value) && (float) $value > 0) {
                return self::epochToIso((float) $value);
            }
        }

        return '';
    }

    /**
     * A numeric epoch as an ISO stamp the timestamp parser reads: seconds (with an optional
     * fraction), or milliseconds when the number is too large to be seconds (past year 5138).
     */
    private static function epochToIso(float $epoch): string
    {
        $ms = $epoch >= 1e11 ? (int) $epoch : (int) round($epoch * 1000);

        return gmdate('Y-m-d\TH:i:s', intdiv($ms, 1000)).sprintf('.%03dZ', $ms % 1000);
    }

    /** Re-encode a JsonFormatter sub-object (context/extra) to a compact string, or null when empty. */
    private function encodePart(mixed $value): ?string
    {
        if (!\is_array($value) || [] === $value) {
            return null;
        }

        $json = json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        return false === $json ? null : $json;
    }

    /**
     * Derives the epoch timestamp of a row from its raw date string.
     *
     * Logs that carry only a time of day (Symfony console: "12:34:56") have no date at all. They
     * are resolved against the file's modification time and flagged `ts_inferred`, so the UI can
     * tell the user those points are a best-effort placement rather than a read value.
     *
     * @param Row $row
     *
     * @return Row
     */
    private function stamp(array $row, int $anchorMs): array
    {
        $raw = $row['date'];
        if ('' === $raw) {
            return $row;
        }

        $ts = $this->timestamps->parse($raw);
        if (null !== $ts) {
            $row['ts'] = $ts;

            return $row;
        }

        if (0 !== $anchorMs && $this->timestamps->isTimeOnly($raw)) {
            $ts = $this->timestamps->parseTimeOnly($raw, $anchorMs);
            if (null !== $ts) {
                // A time later than the file's own mtime belongs to the day before: the file
                // spans a midnight. Covers the common single-crossing case without lookahead.
                if ($ts > $anchorMs + 12 * 3600 * 1000) {
                    $ts -= 86400 * 1000;
                }
                $row['ts'] = $ts;
                $row['ts_inferred'] = true;
            }
        }

        return $row;
    }

    /** Date anchor for time-only logs: the file's last modification time, in epoch ms. */
    private function anchorFor(string $path): int
    {
        $mtime = @filemtime($path);

        return false === $mtime ? 0 : $mtime * 1000;
    }

    /** @return Row */
    private function rawRow(string $line): array
    {
        return ['date' => '', 'ts' => null, 'ts_inferred' => false, 'file' => '', 'channel' => '', 'level' => '', 'message' => $line, 'context' => null, 'extra' => null, 'type' => 'log', 'profile' => null, 'offset' => 0];
    }

    private function statusLevel(int $status): string
    {
        return match (true) {
            $status >= 500 => 'error',
            $status >= 400 => 'warning',
            default => 'info',
        };
    }
}

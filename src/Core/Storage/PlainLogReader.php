<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Core\Storage;

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
 *  - anything else:           kept verbatim as a raw row (never dropped).
 *
 * ANSI colour escape sequences (common in captured console output) are stripped from every line.
 *
 * Monolog limitation: a message that itself embeds a JSON blob may have that fragment
 * misattributed to the context field. Well-formed Monolog output parses correctly.
 *
 * @phpstan-type Row array{date:string,channel:string,level:string,message:string,context:?string,extra:?string}
 */
final class PlainLogReader
{
    /** Default tail window: only the last ~2 MB of a file is read by readTail(). */
    public const DEFAULT_TAIL_BYTES = 2_000_000;

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
            yield from $this->parse($handle);
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
            yield from $this->parse($handle);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param resource $handle
     *
     * @return \Generator<int,Row>
     */
    private function parse($handle): \Generator
    {
        /** @var Row|null $current */
        $current = null;
        $allowContinuation = false;

        while (false !== ($raw = fgets($handle))) {
            $line = rtrim($raw, "\r\n");
            // Strip ANSI colour escapes (captured console output is full of them).
            $line = preg_replace('/\e\[[0-9;]*m/', '', $line) ?? $line;
            if ('' === $line) {
                continue;
            }

            $classified = $this->classify($line);

            if (null !== $classified) {
                if (null !== $current) {
                    yield $current;
                }
                $current = $classified['row'];
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
        if (str_starts_with($line, '{') && null !== ($json = $this->classifyJson($line))) {
            return $json;
        }

        if (preg_match(self::MONOLOG, $line, $m)) {
            return ['monolog' => true, 'row' => [
                'date' => $m['date'],
                'channel' => $m['channel'],
                'level' => strtolower($m['level']),
                'message' => $m['message'],
                'context' => ('' !== ($m['context'] ?? '')) ? $m['context'] : null,
                'extra' => ('' !== ($m['extra'] ?? '')) ? $m['extra'] : null,
            ]];
        }

        if (preg_match(self::CONSOLE, $line, $m)) {
            return ['monolog' => false, 'row' => [
                'date' => $m['date'],
                'channel' => $m['channel'],
                'level' => strtolower($m['level']),
                'message' => $m['message'],
                'context' => null,
                'extra' => null,
            ]];
        }

        if (preg_match(self::NGINX_ACCESS, $line, $m)) {
            return ['monolog' => false, 'row' => [
                'date' => $m['date'],
                'channel' => 'access',
                'level' => $this->statusLevel((int) $m['status']),
                'message' => $m['req'].' — '.$m['status'],
                'context' => null,
                'extra' => null,
            ]];
        }

        if (preg_match(self::NGINX_ERROR, $line, $m)) {
            return ['monolog' => false, 'row' => [
                'date' => $m['date'],
                'channel' => 'nginx',
                'level' => self::ERROR_LEVELS[$m['level']] ?? 'error',
                'message' => $m['message'],
                'context' => null,
                'extra' => null,
            ]];
        }

        if (preg_match(self::PHP_ERROR, $line, $m)) {
            return ['monolog' => false, 'row' => [
                'date' => $m['date'],
                'channel' => 'php',
                'level' => self::ERROR_LEVELS[strtolower($m['level'])] ?? 'error',
                'message' => $m['message'],
                'context' => null,
                'extra' => null,
            ]];
        }

        return null;
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
        if (!\is_array($data) || !isset($data['message'], $data['channel'])) {
            return null;
        }

        $level = $this->jsonLevel($data);
        if (null === $level) {
            return null; // has message+channel but no level marker → not a Monolog record, keep raw
        }

        return ['monolog' => false, 'row' => [
            'date' => $this->jsonDate($data),
            'channel' => \is_string($data['channel']) ? $data['channel'] : '',
            'level' => $level,
            'message' => \is_string($data['message']) ? $data['message'] : (string) json_encode($data['message']),
            'context' => $this->encodePart($data['context'] ?? null),
            'extra' => $this->encodePart($data['extra'] ?? null),
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
     * time (GoogleCloudLogging).
     *
     * @param array<array-key,mixed> $data
     */
    private function jsonDate(array $data): string
    {
        foreach (['datetime', '@timestamp', 'time'] as $key) {
            if (isset($data[$key]) && \is_string($data[$key])) {
                return $data[$key];
            }
        }

        return '';
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

    /** @return Row */
    private function rawRow(string $line): array
    {
        return ['date' => '', 'channel' => '', 'level' => '', 'message' => $line, 'context' => null, 'extra' => null];
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

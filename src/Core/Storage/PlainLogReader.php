<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Core\Storage;

/**
 * Parses plain log files into structured rows, recognising several common formats:
 *  - Monolog LineFormatter:   [date] channel.LEVEL: message {context} {extra}   (+ multi-line traces)
 *  - nginx access (combined): ip - - [date] "METHOD path HTTP/x" status bytes ...
 *  - nginx/PHP error:         2026/06/10 12:40:01 [error] ... | [date] LEVEL: message
 *  - anything else:           kept verbatim as a raw row (never dropped).
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
    private const NGINX_ACCESS = '/^\S+ \S+ \S+ \[(?<date>[^\]]+)\] "(?<req>[^"]*)" (?<status>\d{3}) /';
    private const NGINX_ERROR = '#^(?<date>\d{4}/\d{2}/\d{2} \d{2}:\d{2}:\d{2}) \[(?<level>[a-z]+)\] (?<message>.*)$#';
    private const PHP_ERROR = '/^\[(?<date>[^\]]+)\] (?<level>[A-Z]+): (?<message>.*)$/';

    /** nginx/PHP error level word → our canonical level. */
    private const ERROR_LEVELS = [
        'emerg' => 'emergency', 'alert' => 'alert', 'crit' => 'critical', 'error' => 'error',
        'warn' => 'warning', 'notice' => 'notice', 'info' => 'info', 'debug' => 'debug',
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

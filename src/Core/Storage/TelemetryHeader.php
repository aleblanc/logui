<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Core\Storage;

use Aleblanc\LogUi\Core\Model\LogLevel;

/**
 * The part of a `LOGUI@{json}` line the explorer needs to *list* a request — everything but the
 * records.
 *
 * A telemetry line carries the whole capture, often several kilobytes of records. Decoding that
 * for every line of a production log (which can be mostly telemetry) would make the explorer
 * crawl. Profile::toArray() writes `records` last, so the payload can be cut there and the prefix
 * decoded on its own: a few hundred bytes per line.
 *
 * @phpstan-type Header array{id:string,type:string,at:string,label:string,method:?string,status:?int,duration_ms:float,mem_peak_mb:float,sql:int,slow:int,levels:array<string,int>,exception:bool,failed:bool}
 */
final class TelemetryHeader
{
    private const CUT = ',"records":';

    /**
     * @param string $payload the text after the sentinel; trailing text (Monolog's ` []`) is tolerated
     *
     * @return Header|null null when the text is not a profile
     */
    public static function extract(string $payload): ?array
    {
        $start = strpos($payload, '{');
        $end = strrpos($payload, '}');
        if (false === $start || false === $end || $end < $start) {
            return null;
        }
        $json = substr($payload, $start, $end - $start + 1);

        $data = null;
        $cut = strpos($json, self::CUT);
        if (false !== $cut) {
            $data = json_decode(substr($json, 0, $cut).'}', true);
        }

        // Lines written before `records` moved to the end lose the keys that followed it when
        // cut. `truncated` was one of them: its absence means "old layout", decode it whole.
        if (!\is_array($data) || !\array_key_exists('truncated', $data)) {
            $data = json_decode($json, true);
        }

        if (!\is_array($data) || !isset($data['id'], $data['type'], $data['at'])) {
            return null;
        }

        $type = (string) $data['type'];
        $status = isset($data['status']) ? (int) $data['status'] : null;
        /** @var array<string,mixed> $queries */
        $queries = \is_array($data['queries'] ?? null) ? $data['queries'] : [];

        $levels = [];
        foreach (\is_array($data['levels'] ?? null) ? $data['levels'] : [] as $name => $count) {
            $levels[(string) $name] = (int) $count;
        }

        return [
            'id' => (string) $data['id'],
            'type' => $type,
            'at' => (string) $data['at'],
            'label' => (string) ($data['label'] ?? ''),
            'method' => isset($data['method']) ? (string) $data['method'] : null,
            'status' => $status,
            'duration_ms' => (float) ($data['duration_ms'] ?? 0),
            'mem_peak_mb' => (float) ($data['mem_peak_mb'] ?? 0),
            'sql' => (int) ($queries['count'] ?? 0),
            'slow' => \is_array($queries['slow'] ?? null) ? \count($queries['slow']) : 0,
            'levels' => $levels,
            'exception' => \is_array($data['exception'] ?? null),
            'failed' => self::failed($type, $status),
        ];
    }

    /** An HTTP request failed when it answered 5xx; a command failed when it exited non-zero. */
    public static function failed(string $type, ?int $status): bool
    {
        if (null === $status) {
            return false;
        }

        return 'cli' === $type ? 0 !== $status : $status >= 500;
    }

    /**
     * The level a profile row carries in the explorer: its worst record level, raised to `error`
     * when the request itself failed. A request that logged nothing is `info`.
     *
     * @param Header $header
     */
    public static function level(array $header): string
    {
        $worst = 'info';
        $rank = -1;
        foreach ($header['levels'] as $name => $count) {
            $severity = LogLevel::severity($name);
            if ($count > 0 && $severity > $rank) {
                $rank = $severity;
                $worst = strtolower($name);
            }
        }

        if ($header['failed'] && $rank < LogLevel::severity('error')) {
            return 'error';
        }

        return $worst;
    }

    /** Profile type → explorer row type. */
    public static function rowType(string $profileType): string
    {
        return 'cli' === $profileType ? 'command' : 'request';
    }
}

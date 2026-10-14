<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Core\Query;

use Aleblanc\LogUi\Core\Model\LogLevel;

/**
 * Groups log messages by shape.
 *
 * Two lines that differ only by a number, an id or a timestamp are "the same log" for anyone
 * triaging — `User 42 logged in` and `User 99 logged in` are one event, not two. Normalising the
 * variable parts to placeholders turns a wall of lines into a short list of patterns with counts,
 * which is how you find the one that is suddenly firing a thousand times an hour.
 *
 * The counter is bounded: past {@see MAX_DISTINCT} distinct patterns, the rest fold into `<other>`
 * so a high-cardinality log can never blow up memory.
 */
final class Patterns
{
    public const MAX_DISTINCT = 5000;

    /** Order matters: a UUID must be caught before the generic hex and number rules reach it. */
    private const RULES = [
        '/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i' => '<uuid>',
        '/\b\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:[+-]\d{2}:?\d{2}|Z)?\b/' => '<date>',
        '/\b(?:\d{1,3}\.){3}\d{1,3}\b/' => '<ip>',
        '/\b[0-9a-f]{12,}\b/i' => '<hex>',
        '/"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'/' => '<str>',
        '/\b\d+(?:\.\d+)?\b/' => '<n>',
    ];

    /** @var array<string,array{count:int,sample:string,level:string,rank:int}> */
    private array $seen = [];

    public static function normalize(string $message): string
    {
        $line = strtok($message, "\n") ?: '';
        $line = substr($line, 0, 300);
        $line = preg_replace(array_keys(self::RULES), array_values(self::RULES), $line) ?? $line;

        return trim(preg_replace('/\s+/', ' ', $line) ?? $line);
    }

    public function add(string $message, string $level): void
    {
        $key = self::normalize($message);
        if (!isset($this->seen[$key])) {
            if (\count($this->seen) >= self::MAX_DISTINCT) {
                $key = '<other>';
            }
            $this->seen[$key] ??= ['count' => 0, 'sample' => $message, 'level' => $level, 'rank' => LogLevel::severity($level)];
        }
        ++$this->seen[$key]['count'];
        if (LogLevel::severity($level) > $this->seen[$key]['rank']) {
            $this->seen[$key]['level'] = $level;
            $this->seen[$key]['rank'] = LogLevel::severity($level);
        }
    }

    /** @return list<array{pattern:string,count:int,sample:string,level:string}> */
    public function top(int $n): array
    {
        $all = $this->seen;
        uasort($all, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        $out = [];
        foreach (\array_slice($all, 0, $n, true) as $pattern => $v) {
            $out[] = ['pattern' => $pattern, 'count' => $v['count'], 'sample' => substr(strtok($v['sample'], "\n") ?: '', 0, 300), 'level' => $v['level']];
        }

        return $out;
    }
}

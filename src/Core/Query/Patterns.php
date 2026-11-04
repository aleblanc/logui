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

    /** Bound on the message → pattern memo. */
    private const MEMO_MAX = 10_000;

    /** @var array<string,array{count:int,sample:string,level:string,rank:int}> */
    private array $seen = [];

    /**
     * Pattern of each message already normalised. Logs repeat themselves verbatim (a route matched,
     * an event notified) and normalising runs six regexes: the second time is a hash lookup.
     *
     * @var array<string,string>
     */
    private array $memo = [];

    public static function normalize(string $message): string
    {
        $line = strtok($message, "\n") ?: '';
        $line = substr($line, 0, 300);
        $line = preg_replace(array_keys(self::RULES), array_values(self::RULES), $line) ?? $line;

        return trim(preg_replace('/\s+/', ' ', $line) ?? $line);
    }

    /** @param int $weight how many records this one stands for — more than 1 when sampling */
    public function add(string $message, string $level, int $weight = 1): void
    {
        $this->count($this->keyOf($message), $weight, $message, $level);
    }

    /** Counts a message whose pattern is already known — read back from an index. */
    public function addKey(string $pattern, string $sample, string $level, int $count): void
    {
        $this->count($pattern, $count, $sample, $level);
    }

    /** {@see normalize()}, memoised: logs repeat themselves verbatim. */
    public function keyOf(string $message): string
    {
        if (isset($this->memo[$message])) {
            return $this->memo[$message];
        }
        if (\count($this->memo) >= self::MEMO_MAX) {
            $this->memo = [];
        }

        return $this->memo[$message] = self::normalize($message);
    }

    /** Folds another counter into this one — the per-file counters of one histogram. */
    public function merge(self $other, float $factor = 1.0): void
    {
        foreach ($other->seen as $key => $v) {
            $this->count((string) $key, 1.0 === $factor ? $v['count'] : (int) round($v['count'] * $factor), $v['sample'], $v['level']);
        }
    }

    private function count(string $key, int $weight, string $sample, string $level): void
    {
        if (!isset($this->seen[$key])) {
            if (\count($this->seen) >= self::MAX_DISTINCT) {
                $key = '<other>';
            }
            $this->seen[$key] ??= ['count' => 0, 'sample' => $sample, 'level' => $level, 'rank' => LogLevel::severity($level)];
        }
        $this->seen[$key]['count'] += $weight;
        $rank = LogLevel::severity($level);
        if ($rank > $this->seen[$key]['rank']) {
            $this->seen[$key]['level'] = $level;
            $this->seen[$key]['rank'] = $rank;
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

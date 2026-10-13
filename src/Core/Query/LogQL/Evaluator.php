<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Core\Query\LogQL;

/**
 * Applies a parsed {@see Query} to log rows, as a streaming predicate.
 *
 * Nothing is materialised: rows are tested one by one and dropped as soon as a stage rejects
 * them, which keeps memory flat whatever the window size. Stages run in the order they were
 * written, and the stream selector is evaluated first — it is the cheapest filter and usually
 * the most selective, so the JSON payload is only decoded for rows that survived it.
 *
 * @phpstan-import-type Row from \Aleblanc\LogUi\Core\Storage\PlainLogReader
 */
final class Evaluator
{
    /** Row fields usable as stream-selector labels. */
    private const LABELS = ['file', 'level', 'channel'];

    public function __construct(private readonly Query $query)
    {
    }

    /**
     * @param Row $row
     */
    public function matches(array $row): bool
    {
        foreach ($this->query->selectors as $matcher) {
            if (!$matcher->matches($this->label($row, $matcher->label))) {
                return false;
            }
        }

        $line = null;
        $labels = null;

        foreach ($this->query->stages as $stage) {
            if ($stage instanceof LineFilter) {
                $line ??= $this->line($row);
                if (!$stage->matches($line)) {
                    return false;
                }
                continue;
            }

            if ($stage instanceof JsonParser) {
                $labels ??= $this->labels($row);
                continue;
            }

            if ($stage instanceof LabelFilter) {
                // `| status > 500` without an explicit `| json` still works: the payload is
                // decoded on demand rather than failing on a technicality.
                $labels ??= $this->labels($row);
                // An absent label stays null so that neither `= "x"` nor `!= "x"` can match it:
                // a filter on a field the record does not carry must simply reject the record.
                if (!$stage->matches($labels[$stage->label] ?? null)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Filters a stream of rows.
     *
     * @param iterable<Row> $rows
     *
     * @return \Generator<int,Row>
     */
    public function filter(iterable $rows): \Generator
    {
        if ($this->query->isEmpty()) {
            yield from $rows;

            return;
        }

        foreach ($rows as $row) {
            if ($this->matches($row)) {
                yield $row;
            }
        }
    }

    /**
     * Value of a stream label for a row; unknown labels resolve to an empty string so a
     * selector on a label the row doesn't carry simply doesn't match.
     *
     * @param Row $row
     */
    private function label(array $row, string $label): string
    {
        if (\in_array($label, self::LABELS, true)) {
            /** @var string $value */
            $value = $row[$label];

            return $value;
        }

        return '';
    }

    /**
     * The text a line filter sees: the message plus its context, which is where most of the
     * interesting values live in a Monolog record.
     *
     * @param Row $row
     */
    private function line(array $row): string
    {
        $line = $row['message'];
        if (null !== $row['context'] && '' !== $row['context']) {
            $line .= ' '.$row['context'];
        }
        if (null !== $row['extra'] && '' !== $row['extra']) {
            $line .= ' '.$row['extra'];
        }

        return $line;
    }

    /**
     * Labels exposed by `| json`: the decoded context and extra payloads, flattened with dotted
     * keys so nested values stay reachable (`| user.id = 42`). The row's own dimensions are
     * included too, which makes `| level = "error"` behave as expected.
     *
     * @param Row $row
     *
     * @return array<string,mixed>
     */
    private function labels(array $row): array
    {
        $labels = [
            'file' => $row['file'],
            'level' => $row['level'],
            'channel' => $row['channel'],
        ];

        foreach (['context', 'extra'] as $part) {
            $json = $row[$part];
            if (null === $json || '' === $json) {
                continue;
            }
            $decoded = json_decode($json, true);
            if (\is_array($decoded)) {
                $labels += $this->flatten($decoded);
            }
        }

        return $labels;
    }

    /**
     * @param array<array-key,mixed> $data
     *
     * @return array<string,mixed>
     */
    private function flatten(array $data, string $prefix = ''): array
    {
        $flat = [];
        foreach ($data as $key => $value) {
            $name = '' === $prefix ? (string) $key : $prefix.'.'.$key;
            if (\is_array($value)) {
                $flat += $this->flatten($value, $name);
                continue;
            }
            $flat[$name] = $value;
        }

        return $flat;
    }
}

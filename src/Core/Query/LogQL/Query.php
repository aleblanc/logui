<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Core\Query\LogQL;

/**
 * A parsed LogQL-lite query: a stream selector plus an ordered pipeline of stages.
 *
 * LogUI implements the *selection* subset of LogQL — enough to express what people actually type
 * when hunting through logs, and nothing that would require an index or a time-series database:
 *
 *   {file="var/log/dev.log", level="error"} |= "payment" != "healthcheck" | json | status >= 500
 *
 * Metric queries (`rate()`, `sum by (…)`) are deliberately out of scope; the only aggregation is
 * the implicit `count_over_time` used to build the histogram.
 */
final class Query
{
    /**
     * @param list<LabelMatcher> $selectors stream selector — matched against the row's own labels
     * @param list<Stage>        $stages    line filters, parsers and label filters, in order
     */
    public function __construct(
        public readonly array $selectors,
        public readonly array $stages,
        public readonly string $source = '',
    ) {
    }

    /** True when the query selects everything (no selector, no stage). */
    public function isEmpty(): bool
    {
        return [] === $this->selectors && [] === $this->stages;
    }

    /** True when any stage needs the JSON payload to be decoded. */
    public function needsJson(): bool
    {
        foreach ($this->stages as $stage) {
            if ($stage instanceof JsonParser || $stage instanceof LabelFilter) {
                return true;
            }
        }

        return false;
    }
}

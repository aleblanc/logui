<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Core\Query;

use Aleblanc\LogUi\Core\Storage\PlainLogReader;

/**
 * Everything the explorer's controls contribute next to the query box: the search term, and the
 * types, levels and channels ticked in the fields panel.
 *
 * This is deliberately *not* folded into the LogQL query. Rewriting the user's text every time a
 * checkbox moves would fight whatever they are in the middle of typing, and round-tripping a
 * generated `{level=~"a|b"}` back into checkboxes is guesswork. Keeping the two apart means the
 * query box stays theirs and the facets stay exact — the two are simply ANDed when reading.
 *
 * An empty list means "no restriction", not "nothing": unticking everything is how you go back to
 * seeing all of it, which is what people expect from a facet list.
 *
 * @phpstan-import-type Row from PlainLogReader
 */
final class Selection
{
    /** @var array<string,true> */
    private readonly array $levels;

    /** @var array<string,true> */
    private readonly array $channels;

    /** @var array<string,true> */
    private readonly array $types;

    /**
     * @param list<string> $levels
     * @param list<string> $channels
     * @param list<string> $excluding records mentioning any of these paths are dropped — see {@see matchesSearch()}
     * @param list<string> $types     row types to keep: log, request, command
     * @param string|null  $pattern   normalised message pattern to keep — see {@see Patterns}
     */
    public function __construct(
        array $levels = [],
        array $channels = [],
        private readonly ?Grep $grep = null,
        private readonly array $excluding = [],
        array $types = [],
        private readonly ?string $pattern = null,
    ) {
        // Indexed by value: matching happens once per row, so it must be a hash lookup.
        $this->levels = array_fill_keys(array_map(strtolower(...), $levels), true);
        $this->channels = array_fill_keys($channels, true);
        $this->types = array_fill_keys($types, true);
    }

    public function isEmpty(): bool
    {
        return [] === $this->levels && [] === $this->channels && [] === $this->types && null === $this->grep && [] === $this->excluding && null === $this->pattern;
    }

    /** @param Row $row */
    public function matches(array $row): bool
    {
        return $this->matchesSearch($row) && $this->matchesFacets($row) && $this->matchesPattern($row);
    }

    /**
     * The pattern half — keep only rows whose message normalises to the picked pattern.
     *
     * Applied after the counts (like the facets), so picking one pattern narrows the chart without
     * erasing the others from the list you picked it from.
     *
     * @param Row $row
     */
    public function matchesPattern(array $row): bool
    {
        return null === $this->pattern || Patterns::normalize($row['message']) === $this->pattern;
    }

    /**
     * The search half — the grep box, plus the records to pretend do not exist.
     *
     * Split out because it narrows the result set the way the query does, so the facet counts
     * must be taken *after* it: searching for `payment` should re-count which levels the matching
     * lines carry, and a channel made entirely of noise should not be offered as a facet.
     *
     * @param Row $row
     */
    public function matchesSearch(array $row): bool
    {
        foreach ($this->excluding as $path) {
            if ($this->mentions($row, $path)) {
                return false;
            }
        }

        return null === $this->grep || $this->grep->matches($row);
    }

    /**
     * Does this record talk about that path?
     *
     * Used to drop LogUI's own HTTP traffic. Watching a live tail means polling, and polling is
     * itself logged — by the access log, and by the framework's router — so the view fills up
     * with the act of looking at it. LogUI cannot stop those lines being *written* (nginx and the
     * host's Monolog are not its to configure), so it hides them at read time instead.
     *
     * The path shows up in the message for an access log and in the context for a framework
     * record, hence both.
     *
     * @param Row $row
     */
    private function mentions(array $row, string $path): bool
    {
        return str_contains($row['message'], $path)
            || (null !== $row['context'] && str_contains($row['context'], $path));
    }

    /**
     * The facet half — the ticked levels and channels.
     *
     * Applied *after* the counts are taken, so ticking `error` narrows the chart without erasing
     * every other level from the list you picked it from.
     *
     * @param Row $row
     */
    public function matchesFacets(array $row): bool
    {
        if ([] !== $this->types && !isset($this->types[$row['type']])) {
            return false;
        }

        if ([] !== $this->levels && !isset($this->levels[strtolower($row['level'])])) {
            return false;
        }

        return [] === $this->channels || isset($this->channels[$row['channel']]);
    }
}

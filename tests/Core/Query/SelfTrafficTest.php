<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Tests\Core\Query;

use Aleblanc\LogUi\Core\Query\Grep;
use Aleblanc\LogUi\Core\Query\Selection;
use Aleblanc\LogUi\Core\Storage\PlainLogReader;
use PHPUnit\Framework\TestCase;

/**
 * Looking at a live tail means polling, and the polling is itself logged. Without this filter the
 * explorer fills up with the act of looking at it — measured at 42 % of the records on a quiet
 * application.
 *
 * @phpstan-import-type Row from PlainLogReader
 */
final class SelfTrafficTest extends TestCase
{
    /** @return Row */
    private function row(string $message, ?string $context = null, string $channel = 'access'): array
    {
        return [
            'date' => '2026-10-13T12:00:00+00:00',
            'ts' => 1_791_892_800_000,
            'ts_inferred' => false,
            'file' => 'var/log/access.log',
            'channel' => $channel,
            'level' => 'info',
            'message' => $message,
            'context' => $context,
            'extra' => null,
            'type' => 'log',
            'profile' => null,
            'offset' => 0,
        ];
    }

    public function test_an_access_log_line_for_the_ui_is_dropped(): void
    {
        $selection = new Selection([], [], null, ['/_logui']);

        self::assertFalse($selection->matches($this->row('GET /_logui/explore/query?q=&from=1 HTTP/1.0 — 200')));
    }

    /** A framework record names the route, not the path — the path is in the context. */
    public function test_a_framework_record_is_dropped_via_its_context(): void
    {
        $selection = new Selection([], [], null, ['/_logui']);
        $row = $this->row(
            'Matched route "logui_explore_query".',
            '{"request_uri":"http://localhost/_logui/explore/query","method":"GET"}',
            'request',
        );

        self::assertFalse($selection->matches($row));
    }

    public function test_the_application_s_own_traffic_is_kept(): void
    {
        $selection = new Selection([], [], null, ['/_logui']);

        self::assertTrue($selection->matches($this->row('GET /orders/42 HTTP/1.1 — 200')));
        self::assertTrue($selection->matches($this->row('Payment failed', '{"order":42}', 'app')));
    }

    public function test_nothing_is_dropped_when_the_filter_is_off(): void
    {
        $selection = new Selection();

        self::assertTrue($selection->isEmpty());
        self::assertTrue($selection->matches($this->row('GET /_logui/explore/query HTTP/1.0 — 200')));
    }

    public function test_a_selection_that_only_hides_noise_is_not_empty(): void
    {
        // isEmpty() is what callers use to skip the filter entirely; it must account for this.
        self::assertFalse((new Selection([], [], null, ['/_logui']))->isEmpty());
    }

    /**
     * The noise is removed in the search stage, before the facets are counted — otherwise the
     * fields panel would offer `access: 500` when 480 of those are LogUI polling itself.
     */
    public function test_the_noise_is_removed_before_the_facets_are_counted(): void
    {
        $selection = new Selection([], [], null, ['/_logui']);
        $noise = $this->row('GET /_logui/explore/query HTTP/1.0 — 200');

        self::assertFalse($selection->matchesSearch($noise), 'dropped by the search stage');
        self::assertTrue($selection->matchesFacets($noise), 'not by the facet stage');
    }

    public function test_it_combines_with_a_search(): void
    {
        $selection = new Selection([], [], Grep::of('explore'), ['/_logui']);

        // Matches the grep, but it is still LogUI looking at itself.
        self::assertFalse($selection->matches($this->row('GET /_logui/explore/query HTTP/1.0 — 200')));
        self::assertTrue($selection->matches($this->row('GET /shop/explore HTTP/1.1 — 200')));
    }

    public function test_a_custom_ui_path_is_honoured(): void
    {
        $selection = new Selection([], [], null, ['/admin/logs']);

        self::assertFalse($selection->matches($this->row('GET /admin/logs/explore/query HTTP/1.0 — 200')));
        self::assertTrue($selection->matches($this->row('GET /_logui/explore/query HTTP/1.0 — 200')));
    }

    /**
     * `ui_path` is the address a browser uses. Behind a proxy that mounts the app under a prefix
     * the access log records the internal path, so both have to be matched or the filter silently
     * does nothing — which is exactly what happened the first time.
     */
    public function test_a_prefix_stripping_proxy_is_handled(): void
    {
        $selection = new Selection([], [], null, ['/shop/_logui', '/_logui']);

        self::assertFalse($selection->matches($this->row('GET /shop/_logui/explore/query HTTP/1.1 — 200')), 'external path');
        self::assertFalse($selection->matches($this->row('GET /_logui/explore/query HTTP/1.0 — 200')), 'internal path');
        self::assertTrue($selection->matches($this->row('GET /shop/checkout HTTP/1.1 — 200')));
    }
}

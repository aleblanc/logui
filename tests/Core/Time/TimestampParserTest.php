<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Tests\Core\Time;

use Aleblanc\LogUi\Core\Time\TimestampParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TimestampParserTest extends TestCase
{
    private const UTC_2026_10_13_123456 = 1_791_894_896_000; // 2026-10-13T12:34:56Z

    #[DataProvider('absoluteFormats')]
    public function test_parses_every_known_format(string $raw, int $expectedMs, string $timezone): void
    {
        self::assertSame($expectedMs, (new TimestampParser($timezone))->parse($raw));
    }

    /**
     * @return \Generator<string,array{string,int,string}>
     */
    public static function absoluteFormats(): \Generator
    {
        // Monolog ISO with explicit offset — the timezone argument must be ignored.
        yield 'monolog iso +02:00' => ['2026-10-13T14:34:56+02:00', self::UTC_2026_10_13_123456, 'UTC'];
        yield 'monolog iso Z' => ['2026-10-13T12:34:56Z', self::UTC_2026_10_13_123456, 'Europe/Paris'];
        yield 'monolog iso micros' => ['2026-10-13T12:34:56.789012Z', self::UTC_2026_10_13_123456 + 789, 'UTC'];
        yield 'monolog iso millis' => ['2026-10-13T12:34:56.5Z', self::UTC_2026_10_13_123456 + 500, 'UTC'];
        yield 'monolog iso -0500' => ['2026-10-13T07:34:56-0500', self::UTC_2026_10_13_123456, 'UTC'];

        // Formats without offset — interpreted in the given timezone.
        yield 'monolog legacy utc' => ['2026-10-13 12:34:56', self::UTC_2026_10_13_123456, 'UTC'];
        yield 'monolog legacy paris' => ['2026-10-13 14:34:56', self::UTC_2026_10_13_123456, 'Europe/Paris'];
        yield 'nginx error' => ['2026/10/13 14:34:56', self::UTC_2026_10_13_123456, 'Europe/Paris'];
        yield 'php error' => ['13-Oct-2026 14:34:56 Europe/Paris', self::UTC_2026_10_13_123456, 'Europe/Paris'];

        // nginx access carries its own offset.
        yield 'nginx access' => ['13/Oct/2026:14:34:56 +0200', self::UTC_2026_10_13_123456, 'UTC'];
        yield 'nginx access utc' => ['13/Oct/2026:12:34:56 +0000', self::UTC_2026_10_13_123456, 'Europe/Paris'];
    }

    #[DataProvider('unparseable')]
    public function test_returns_null_on_unusable_input(string $raw): void
    {
        self::assertNull((new TimestampParser('UTC'))->parse($raw));
    }

    /**
     * @return \Generator<string,array{string}>
     */
    public static function unparseable(): \Generator
    {
        yield 'empty' => [''];
        yield 'too short' => ['12:34'];
        yield 'free text' => ['not a date at all'];
        yield 'time only' => ['12:34:56'];          // needs an anchor, see parseTimeOnly()
        yield 'unknown month' => ['13/Zzz/2026:12:34:56 +0000'];
        yield 'truncated iso' => ['2026-10-13T12:34'];
    }

    public function test_dst_is_honoured_across_the_switch(): void
    {
        $parser = new TimestampParser('Europe/Paris');

        // 2026-03-29 02:00 local is the European spring-forward: before it CET (+1), after CEST (+2).
        $winter = $parser->parse('2026-03-29 01:30:00');
        $summer = $parser->parse('2026-03-29 03:30:00');

        self::assertNotNull($winter);
        self::assertNotNull($summer);
        // 01:30 CET = 00:30 UTC, 03:30 CEST = 01:30 UTC → exactly one hour apart in real time.
        self::assertSame(3_600_000, $summer - $winter);
    }

    public function test_offset_cache_does_not_leak_across_days(): void
    {
        $parser = new TimestampParser('Europe/Paris');

        $beforeSwitch = $parser->parse('2026-03-28 12:00:00'); // CET  (+1)
        $afterSwitch = $parser->parse('2026-03-30 12:00:00');  // CEST (+2)

        self::assertNotNull($beforeSwitch);
        self::assertNotNull($afterSwitch);
        // Two calendar days apart, but one hour less in real time because the clocks moved forward.
        self::assertSame(2 * 86_400_000 - 3_600_000, $afterSwitch - $beforeSwitch);
    }

    public function test_time_only_is_resolved_against_anchor_day(): void
    {
        $parser = new TimestampParser('UTC');
        $anchor = $parser->parse('2026-10-13 23:00:00');
        self::assertNotNull($anchor);

        self::assertTrue($parser->isTimeOnly('08:15:30'));
        self::assertSame(
            $parser->parse('2026-10-13 08:15:30'),
            $parser->parseTimeOnly('08:15:30', $anchor),
        );
    }

    public function test_time_only_rejects_invalid_times(): void
    {
        $parser = new TimestampParser('UTC');
        $anchor = (int) (strtotime('2026-10-13 12:00:00 UTC') * 1000);

        self::assertNull($parser->parseTimeOnly('99:99:99', $anchor));
        self::assertNull($parser->parseTimeOnly('2026-10-13 12:34:56', $anchor));
    }

    /**
     * The fast integer path must agree with PHP's own parser on well-formed input.
     */
    #[DataProvider('absoluteFormats')]
    public function test_agrees_with_strtotime(string $raw, int $expectedMs, string $timezone): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set($timezone);

        try {
            $reference = strtotime($raw);
            if (false === $reference) {
                self::markTestSkipped('strtotime does not understand this shape: '.$raw);
            }
            // Compare on whole seconds — strtotime drops sub-second precision.
            self::assertSame($reference, intdiv($expectedMs, 1000), 'disagreement on: '.$raw);
        } finally {
            date_default_timezone_set($previous);
        }
    }
}

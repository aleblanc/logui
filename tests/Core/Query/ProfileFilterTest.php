<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Tests\Core\Query;

use Aleblanc\LogUi\Core\Model\LogRecord;
use Aleblanc\LogUi\Core\Model\Profile;
use Aleblanc\LogUi\Core\Model\ProfileType;
use Aleblanc\LogUi\Core\Model\QueryStats;
use Aleblanc\LogUi\Core\Query\ProfileFilter;
use PHPUnit\Framework\TestCase;

final class ProfileFilterTest extends TestCase
{
    public function test_empty_filter_matches_everything(): void
    {
        self::assertTrue((new ProfileFilter())->matches($this->profile()));
    }

    public function test_level_matches_when_a_counted_level_is_at_least_that_severe(): void
    {
        $profile = $this->profile(levels: ['error' => 2]);

        self::assertTrue((new ProfileFilter(level: 'warning'))->matches($profile));
        self::assertTrue((new ProfileFilter(level: 'error'))->matches($profile));
        self::assertFalse((new ProfileFilter(level: 'critical'))->matches($profile));
    }

    public function test_type_filter(): void
    {
        $profile = $this->profile(type: ProfileType::Cli);

        self::assertTrue((new ProfileFilter(type: ProfileType::Cli))->matches($profile));
        self::assertFalse((new ProfileFilter(type: ProfileType::Http))->matches($profile));
    }

    public function test_method_filter(): void
    {
        $profile = $this->profile(method: 'POST');

        self::assertTrue((new ProfileFilter(method: 'POST'))->matches($profile));
        self::assertFalse((new ProfileFilter(method: 'GET'))->matches($profile));
    }

    public function test_search_matches_label_or_record_message_case_insensitive(): void
    {
        $profile = $this->profile(label: 'GET /Checkout', records: [new LogRecord('info', 'app', 'Payment OK', [], 0.0)]);

        self::assertTrue((new ProfileFilter(search: 'checkout'))->matches($profile));
        self::assertTrue((new ProfileFilter(search: 'payment'))->matches($profile));
        self::assertFalse((new ProfileFilter(search: 'refund'))->matches($profile));
    }

    public function test_min_duration_and_min_memory(): void
    {
        $profile = $this->profile(durationMs: 100.0, memPeakMb: 20.0);

        self::assertTrue((new ProfileFilter(minDurationMs: 50.0))->matches($profile));
        self::assertFalse((new ProfileFilter(minDurationMs: 150.0))->matches($profile));
        self::assertTrue((new ProfileFilter(minMemMb: 10.0))->matches($profile));
        self::assertFalse((new ProfileFilter(minMemMb: 30.0))->matches($profile));
    }

    public function test_rejects_unknown_level(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ProfileFilter(level: 'warn');
    }

    /**
     * @param list<LogRecord>   $records
     * @param array<string,int> $levels
     */
    private function profile(
        ProfileType $type = ProfileType::Http,
        string $label = 'GET /',
        float $durationMs = 1.0,
        float $memPeakMb = 1.0,
        array $records = [],
        ?string $method = null,
        array $levels = [],
    ): Profile {
        return new Profile(
            'id',
            $type,
            new \DateTimeImmutable('2026-06-10T14:00:00+00:00'),
            $label,
            200,
            $durationMs,
            $memPeakMb,
            QueryStats::empty(),
            $levels,
            $records,
            null,
            false,
            $method,
        );
    }
}

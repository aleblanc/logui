<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Tests\Core\Query;

use Aleblanc\LogUi\Core\Model\Profile;
use Aleblanc\LogUi\Core\Model\ProfileType;
use Aleblanc\LogUi\Core\Model\QueryStats;
use Aleblanc\LogUi\Core\Query\ProfileSummary;
use PHPUnit\Framework\TestCase;

final class ProfileSummaryTest extends TestCase
{
    public function test_summarizes_types_and_levels(): void
    {
        $summary = ProfileSummary::of([
            $this->profile(ProfileType::Http, ['error' => 2, 'warning' => 1]),
            $this->profile(ProfileType::Http, ['critical' => 1]),
            $this->profile(ProfileType::Cli, ['warning' => 3]),
        ]);

        self::assertSame(3, $summary->total);
        self::assertSame(2, $summary->http);
        self::assertSame(1, $summary->cli);
        self::assertSame(1, $summary->critical);
        self::assertSame(2, $summary->error);
        self::assertSame(4, $summary->warning);
    }

    public function test_empty(): void
    {
        $summary = ProfileSummary::of([]);

        self::assertSame(0, $summary->total);
        self::assertSame(0, $summary->error);
    }

    /** @param array<string,int> $levels */
    private function profile(ProfileType $type, array $levels): Profile
    {
        return new Profile('id', $type, new \DateTimeImmutable('2026-06-10T14:00:00+00:00'), 'x', 200, 1.0, 1.0, QueryStats::empty(), $levels, [], null, false);
    }
}

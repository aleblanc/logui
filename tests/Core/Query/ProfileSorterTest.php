<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Tests\Core\Query;

use Aleblanc\LogUi\Core\Model\Profile;
use Aleblanc\LogUi\Core\Model\ProfileType;
use Aleblanc\LogUi\Core\Model\QueryStats;
use Aleblanc\LogUi\Core\Query\ProfileSorter;
use PHPUnit\Framework\TestCase;

final class ProfileSorterTest extends TestCase
{
    public function test_sorts_by_duration_desc_by_default(): void
    {
        $sorted = (new ProfileSorter())->sort(
            [$this->profile('a', 50.0), $this->profile('b', 200.0), $this->profile('c', 100.0)],
            field: 'duration',
        );

        self::assertSame(['b', 'c', 'a'], array_map(static fn (Profile $p): string => $p->id, $sorted));
    }

    public function test_sorts_ascending_when_requested(): void
    {
        $sorted = (new ProfileSorter())->sort(
            [$this->profile('a', 50.0), $this->profile('b', 200.0)],
            field: 'duration',
            direction: 'asc',
        );

        self::assertSame(['a', 'b'], array_map(static fn (Profile $p): string => $p->id, $sorted));
    }

    public function test_unknown_field_falls_back_to_at(): void
    {
        $sorted = (new ProfileSorter())->sort(
            [$this->profile('old', 1.0, '2026-06-10T10:00:00+00:00'), $this->profile('new', 1.0, '2026-06-10T12:00:00+00:00')],
            field: 'bogus',
        );

        self::assertSame('new', $sorted[0]->id);
    }

    public function test_rejects_invalid_direction(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ProfileSorter())->sort([], field: 'duration', direction: 'sideways');
    }

    public function test_sorts_by_status_treating_null_as_zero(): void
    {
        $a = new Profile('a', ProfileType::Http, new \DateTimeImmutable('2026-06-10T14:00:00+00:00'), 'x', 500, 1.0, 1.0, QueryStats::empty(), [], [], null, false);
        $b = new Profile('b', ProfileType::Cli, new \DateTimeImmutable('2026-06-10T14:00:00+00:00'), 'y', null, 1.0, 1.0, QueryStats::empty(), [], [], null, false);

        $sorted = (new ProfileSorter())->sort([$b, $a], field: 'status', direction: 'desc');

        self::assertSame(['a', 'b'], array_map(static fn ($p): string => $p->id, $sorted));
    }

    private function profile(string $id, float $durationMs, string $at = '2026-06-10T14:00:00+00:00'): Profile
    {
        return new Profile(
            $id,
            ProfileType::Http,
            new \DateTimeImmutable($at),
            'GET /',
            200,
            $durationMs,
            1.0,
            QueryStats::empty(),
            [],
            [],
            null,
            false,
        );
    }
}

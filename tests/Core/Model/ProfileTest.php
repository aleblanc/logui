<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Tests\Core\Model;

use Aleblanc\LogUi\Core\Model\LogRecord;
use Aleblanc\LogUi\Core\Model\Profile;
use Aleblanc\LogUi\Core\Model\ProfileType;
use Aleblanc\LogUi\Core\Model\QueryStats;
use PHPUnit\Framework\TestCase;

final class ProfileTest extends TestCase
{
    public function test_round_trips_through_array(): void
    {
        $profile = new Profile(
            id: '01ABC',
            type: ProfileType::Http,
            at: new \DateTimeImmutable('2026-06-10T14:03:11+00:00'),
            label: 'GET /checkout',
            status: 200,
            durationMs: 182.4,
            memPeakMb: 24.1,
            queries: new QueryStats(2, [['sql' => 'SELECT 1', 'ms' => 91.2]]),
            levels: ['warning' => 1],
            records: [new LogRecord('warning', 'app', 'slow', [], 12.0)],
            exception: null,
            truncated: false,
        );

        $restored = Profile::fromArray($profile->toArray());

        self::assertSame('01ABC', $restored->id);
        self::assertSame(ProfileType::Http, $restored->type);
        self::assertSame('2026-06-10T14:03:11+00:00', $restored->at->format(\DateTimeInterface::ATOM));
        self::assertSame('GET /checkout', $restored->label);
        self::assertSame(200, $restored->status);
        self::assertSame(182.4, $restored->durationMs);
        self::assertSame(24.1, $restored->memPeakMb);
        self::assertSame(2, $restored->queries->count);
        self::assertSame(['warning' => 1], $restored->levels);
        self::assertCount(1, $restored->records);
        self::assertSame('slow', $restored->records[0]->message);
        self::assertNull($restored->exception);
        self::assertFalse($restored->truncated);
    }

    public function test_to_array_serializes_at_as_atom_string(): void
    {
        $profile = self::minimal();

        self::assertSame('2026-06-10T14:03:11+00:00', $profile->toArray()['at']);
    }

    public function test_round_trips_exception(): void
    {
        $profile = self::minimal(exception: ['class' => 'RuntimeException', 'message' => 'boom', 'trace' => '#0 ...']);

        $restored = Profile::fromArray($profile->toArray());

        self::assertNotNull($restored->exception);
        self::assertSame('RuntimeException', $restored->exception['class']);
    }

    public function test_round_trips_http_method(): void
    {
        $restored = Profile::fromArray(self::minimal(method: 'PATCH')->toArray());

        self::assertSame('PATCH', $restored->method);
    }

    public function test_method_defaults_to_null(): void
    {
        self::assertNull(self::minimal()->method);
    }

    /** @param array{class:string,message:string,trace:string}|null $exception */
    private static function minimal(?array $exception = null, ?string $method = null): Profile
    {
        return new Profile(
            id: 'x',
            type: ProfileType::Cli,
            at: new \DateTimeImmutable('2026-06-10T14:03:11+00:00'),
            label: 'app:run',
            status: 0,
            durationMs: 1.0,
            memPeakMb: 1.0,
            queries: QueryStats::empty(),
            levels: [],
            records: [],
            exception: $exception,
            truncated: false,
            method: $method,
        );
    }
}

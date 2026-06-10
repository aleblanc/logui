<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Tests\Core\Capture;

use Aleblanc\LogUi\Core\Capture\ProfileContext;
use Aleblanc\LogUi\Core\Capture\RecordBuffer;
use Aleblanc\LogUi\Core\Capture\Redactor;
use Aleblanc\LogUi\Core\Model\ProfileType;
use Aleblanc\LogUi\Core\Stats\MemoryProbe;
use Aleblanc\LogUi\Core\Stats\QueryCounter;
use Aleblanc\LogUi\Tests\Support\FixedClock;
use PHPUnit\Framework\TestCase;

final class ProfileContextTest extends TestCase
{
    public function test_finish_builds_profile_with_duration_and_records(): void
    {
        $clock = new FixedClock(new \DateTimeImmutable('2026-06-10T14:00:00+00:00'), micro: 100.0);
        $context = $this->context($clock);

        $context->addRecord('warning', 'app', 'careful', ['password' => 'x']);
        $clock->advanceMs(182.4);

        $profile = $context->finish(status: 200);

        self::assertSame('id-1', $profile->id);
        self::assertSame(ProfileType::Http, $profile->type);
        self::assertSame('GET /', $profile->label);
        self::assertSame(200, $profile->status);
        self::assertEqualsWithDelta(182.4, $profile->durationMs, 0.01);
        self::assertSame(['warning' => 1], $profile->levels);
        self::assertCount(1, $profile->records);
    }

    public function test_redacts_record_context(): void
    {
        $context = $this->context(new FixedClock(new \DateTimeImmutable('2026-06-10T14:00:00+00:00')));

        $context->addRecord('info', 'app', 'login', ['password' => 'hunter2']);

        self::assertSame('***', $context->finish(null)->records[0]->context['password']);
    }

    public function test_set_label_updates_label(): void
    {
        $context = $this->context(new FixedClock(new \DateTimeImmutable('2026-06-10T14:00:00+00:00')));

        $context->setLabel('GET /checkout');

        self::assertSame('GET /checkout', $context->finish(200)->label);
    }

    public function test_records_queries(): void
    {
        $context = $this->context(new FixedClock(new \DateTimeImmutable('2026-06-10T14:00:00+00:00')));

        $context->recordQuery('SELECT 1', 91.0);

        self::assertSame(1, $context->finish(200)->queries->count);
    }

    public function test_exception_is_null_by_default(): void
    {
        $context = $this->context(new FixedClock(new \DateTimeImmutable('2026-06-10T14:00:00+00:00')));

        self::assertNull($context->finish(200)->exception);
    }

    public function test_set_exception_records_class_and_message(): void
    {
        $context = $this->context(new FixedClock(new \DateTimeImmutable('2026-06-10T14:00:00+00:00')));

        $context->setException(new \RuntimeException('boom'));

        $exception = $context->finish(500)->exception;
        self::assertNotNull($exception);
        self::assertSame(\RuntimeException::class, $exception['class']);
        self::assertSame('boom', $exception['message']);
    }

    private function context(FixedClock $clock): ProfileContext
    {
        return new ProfileContext(
            id: 'id-1',
            type: ProfileType::Http,
            label: 'GET /',
            clock: $clock,
            buffer: new RecordBuffer(1000),
            queries: new QueryCounter(50.0),
            redactor: new Redactor(['password']),
            memory: new MemoryProbe(),
        );
    }
}

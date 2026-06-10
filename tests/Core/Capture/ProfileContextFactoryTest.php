<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Tests\Core\Capture;

use Aleblanc\LogUi\Core\Capture\ProfileContextFactory;
use Aleblanc\LogUi\Core\Capture\Redactor;
use Aleblanc\LogUi\Core\Model\ProfileType;
use Aleblanc\LogUi\Core\Stats\MemoryProbe;
use Aleblanc\LogUi\Tests\Support\FixedClock;
use PHPUnit\Framework\TestCase;

final class ProfileContextFactoryTest extends TestCase
{
    public function test_creates_a_working_context(): void
    {
        $factory = new ProfileContextFactory(
            clock: new FixedClock(new \DateTimeImmutable('2026-06-10T14:00:00+00:00')),
            redactor: new Redactor(['password']),
            memory: new MemoryProbe(),
            maxRecords: 1000,
            slowMs: 50.0,
        );

        $context = $factory->create('id-9', ProfileType::Cli, 'app:run');
        $context->addRecord('error', 'app', 'boom', []);

        $profile = $context->finish(1);

        self::assertSame('id-9', $profile->id);
        self::assertSame(ProfileType::Cli, $profile->type);
        self::assertSame(['error' => 1], $profile->levels);
    }
}

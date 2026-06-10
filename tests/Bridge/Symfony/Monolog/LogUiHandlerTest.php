<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Tests\Bridge\Symfony\Monolog;

use Aleblanc\LogUi\Bridge\Symfony\Capture\CurrentProfile;
use Aleblanc\LogUi\Bridge\Symfony\Monolog\LogUiHandler;
use Aleblanc\LogUi\Core\Capture\ProfileContextFactory;
use Aleblanc\LogUi\Core\Capture\Redactor;
use Aleblanc\LogUi\Core\Model\ProfileType;
use Aleblanc\LogUi\Core\Stats\MemoryProbe;
use Aleblanc\LogUi\Core\Stats\SystemClock;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

final class LogUiHandlerTest extends TestCase
{
    public function test_forwards_record_to_active_profile(): void
    {
        $current = new CurrentProfile();
        $current->begin(
            (new ProfileContextFactory(new SystemClock(), new Redactor([]), new MemoryProbe(), 1000, 50.0))
                ->create('r1', ProfileType::Http, 'GET /')
        );

        $handler = new LogUiHandler($current);
        $handler->handle(new LogRecord(new \DateTimeImmutable(), 'app', Level::Warning, 'careful', ['k' => 'v']));

        $profile = $current->finish(200);
        self::assertNotNull($profile);
        self::assertCount(1, $profile->records);
        self::assertSame('warning', $profile->records[0]->level);
        self::assertSame('app', $profile->records[0]->channel);
        self::assertSame('careful', $profile->records[0]->message);
    }

    public function test_is_safe_when_no_profile_active(): void
    {
        $handler = new LogUiHandler(new CurrentProfile());

        // Must not throw.
        $handler->handle(new LogRecord(new \DateTimeImmutable(), 'app', Level::Error, 'orphan', []));

        $this->expectNotToPerformAssertions();
    }

    public function test_captures_nothing_when_disabled(): void
    {
        $current = $this->activeProfile();

        $handler = new LogUiHandler($current, enabled: false);
        $handler->handle(new LogRecord(new \DateTimeImmutable(), 'app', Level::Warning, 'careful', []));

        $profile = $current->finish(200);
        self::assertNotNull($profile);
        self::assertCount(0, $profile->records, 'disabled handler records nothing');
    }

    public function test_ignores_its_own_telemetry_line(): void
    {
        $current = $this->activeProfile();

        // The TelemetryLogger writes this through the logger at terminate; it must not
        // be mirrored back into the profile (noise / self-capture).
        $handler = new LogUiHandler($current);
        $handler->handle(new LogRecord(new \DateTimeImmutable(), 'app', Level::Info, 'LOGUI@{"id":"r1"}', []));
        $handler->handle(new LogRecord(new \DateTimeImmutable(), 'app', Level::Info, 'real message', []));

        $profile = $current->finish(200);
        self::assertNotNull($profile);
        self::assertCount(1, $profile->records, 'only the real record is kept');
        self::assertSame('real message', $profile->records[0]->message);
    }

    private function activeProfile(): CurrentProfile
    {
        $current = new CurrentProfile();
        $current->begin(
            (new ProfileContextFactory(new SystemClock(), new Redactor([]), new MemoryProbe(), 1000, 50.0))
                ->create('r1', ProfileType::Http, 'GET /')
        );

        return $current;
    }
}

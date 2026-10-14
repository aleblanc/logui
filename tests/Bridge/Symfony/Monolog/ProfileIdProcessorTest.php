<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Tests\Bridge\Symfony\Monolog;

use Aleblanc\LogUi\Bridge\Symfony\Capture\CurrentProfile;
use Aleblanc\LogUi\Bridge\Symfony\Monolog\ProfileIdProcessor;
use Aleblanc\LogUi\Core\Capture\ProfileContextFactory;
use Aleblanc\LogUi\Core\Capture\Redactor;
use Aleblanc\LogUi\Core\Model\ProfileType;
use Aleblanc\LogUi\Core\Stats\MemoryProbe;
use Aleblanc\LogUi\Core\Stats\SystemClock;
use Aleblanc\LogUi\Core\Storage\TelemetryReader;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

final class ProfileIdProcessorTest extends TestCase
{
    public function test_stamps_the_profile_id_when_one_is_active(): void
    {
        $record = (new ProfileIdProcessor($this->activeProfile()))(
            new LogRecord(new \DateTimeImmutable(), 'app', Level::Info, 'hello', [])
        );

        self::assertSame('r1', $record->extra['logui_id']);
    }

    public function test_leaves_the_record_untouched_when_no_profile_is_active(): void
    {
        $record = (new ProfileIdProcessor(new CurrentProfile()))(
            new LogRecord(new \DateTimeImmutable(), 'app', Level::Info, 'hello', [])
        );

        self::assertArrayNotHasKey('logui_id', $record->extra);
    }

    public function test_never_overwrites_an_existing_id(): void
    {
        $record = (new ProfileIdProcessor($this->activeProfile()))(
            new LogRecord(new \DateTimeImmutable(), 'app', Level::Info, 'hello', [], ['logui_id' => 'keep'])
        );

        self::assertSame('keep', $record->extra['logui_id']);
    }

    public function test_is_a_no_op_when_disabled(): void
    {
        $record = (new ProfileIdProcessor($this->activeProfile(), enabled: false))(
            new LogRecord(new \DateTimeImmutable(), 'app', Level::Info, 'hello', [])
        );

        self::assertArrayNotHasKey('logui_id', $record->extra);
    }

    public function test_never_stamps_the_telemetry_line_itself(): void
    {
        $record = (new ProfileIdProcessor($this->activeProfile()))(
            new LogRecord(new \DateTimeImmutable(), 'app', Level::Info, TelemetryReader::SENTINEL.'{"id":"r1"}', [])
        );

        self::assertArrayNotHasKey('logui_id', $record->extra);
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

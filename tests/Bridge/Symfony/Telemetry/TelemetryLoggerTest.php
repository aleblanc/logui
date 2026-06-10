<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Tests\Bridge\Symfony\Telemetry;

use Aleblanc\LogUi\Bridge\Symfony\Telemetry\TelemetryLogger;
use Aleblanc\LogUi\Core\Model\Profile;
use Aleblanc\LogUi\Core\Model\ProfileType;
use Aleblanc\LogUi\Core\Model\QueryStats;
use Aleblanc\LogUi\Core\Storage\TelemetryReader;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

final class TelemetryLoggerTest extends TestCase
{
    public function test_emits_a_sentinel_line_with_the_metrics_json(): void
    {
        $logger = new Logger('app', [$handler = new TestHandler()]);
        (new TelemetryLogger($logger))->log($this->profile(['error' => 1], 500));

        $records = $handler->getRecords();
        self::assertCount(1, $records);
        self::assertStringStartsWith(TelemetryReader::SENTINEL, $records[0]->message);
        self::assertSame(Level::Error, $records[0]->level, 'a 500 / error profile logs at error level');

        $json = substr($records[0]->message, \strlen(TelemetryReader::SENTINEL));
        $data = json_decode($json, true);
        self::assertSame('GET /x', $data['label']);
        self::assertSame(182.4, $data['duration_ms']);
        self::assertCount(1, $data['records'], 'the request log records travel inside the line');
        self::assertSame('boom', $data['records'][0]['msg']);
    }

    public function test_info_level_for_a_clean_request(): void
    {
        $logger = new Logger('app', [$handler = new TestHandler()]);
        (new TelemetryLogger($logger))->log($this->profile([], 200));

        self::assertSame(Level::Info, $handler->getRecords()[0]->level);
    }

    /** @param array<string,int> $levels */
    private function profile(array $levels, int $status): Profile
    {
        return new Profile('id', ProfileType::Http, new \DateTimeImmutable('2026-06-10T14:00:00+00:00'), 'GET /x', $status, 182.4, 24.1, QueryStats::empty(), $levels, [new \Aleblanc\LogUi\Core\Model\LogRecord('info', 'app', 'boom', [], 1.0)], null, false, 'GET', 18.0, 24.0);
    }
}

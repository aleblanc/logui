<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Tests\Bridge\Symfony\Telemetry;

use Aleblanc\LogUi\Bridge\Symfony\Telemetry\TelemetryLogger;
use Aleblanc\LogUi\Core\Model\LogRecord;
use Aleblanc\LogUi\Core\Model\Profile;
use Aleblanc\LogUi\Core\Model\ProfileType;
use Aleblanc\LogUi\Core\Model\QueryStats;
use Aleblanc\LogUi\Core\Storage\TelemetryReader;
use PHPUnit\Framework\TestCase;

final class TelemetryLoggerTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir().'/logui-telemetry-'.uniqid().'.log';
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }
    }

    public function test_appends_a_sentinel_line_with_the_metrics_json(): void
    {
        (new TelemetryLogger($this->file))->log($this->profile(['error' => 1], 500));

        $content = (string) file_get_contents($this->file);
        self::assertStringStartsWith(TelemetryReader::SENTINEL, $content);
        self::assertStringEndsWith("\n", $content, 'each entry is its own line');

        $json = substr(trim($content), \strlen(TelemetryReader::SENTINEL));
        $data = json_decode($json, true);
        self::assertSame('GET /x', $data['label']);
        self::assertSame(182.4, $data['duration_ms']);
        self::assertCount(1, $data['records'], 'the request log records travel inside the line');
        self::assertSame('boom', $data['records'][0]['msg']);
    }

    public function test_each_call_appends_a_new_line(): void
    {
        $logger = new TelemetryLogger($this->file);
        $logger->log($this->profile([], 200));
        $logger->log($this->profile([], 200));

        $lines = array_filter(explode("\n", (string) file_get_contents($this->file)));
        self::assertCount(2, $lines, 'telemetry accumulates, one line per request');
    }

    public function test_creates_the_file_and_its_missing_directory(): void
    {
        $this->file = sys_get_temp_dir().'/logui-tl-'.uniqid().'/nested/telemetry.log';

        (new TelemetryLogger($this->file))->log($this->profile([], 200));

        self::assertFileExists($this->file);

        unlink($this->file);
        rmdir(\dirname($this->file));
        rmdir(\dirname($this->file, 2));
    }

    public function test_a_round_trip_is_readable_by_the_reader(): void
    {
        // The whole point of writing directly to the file: the reader reads it back, with no
        // dependency on the host's Monolog routing (fingers_crossed / rotating_file in prod).
        (new TelemetryLogger($this->file))->log($this->profile([], 200));

        $profiles = (new TelemetryReader())->read($this->file);
        self::assertCount(1, $profiles);
        self::assertSame('GET /x', $profiles[0]->label);
    }

    /** @param array<string,int> $levels */
    private function profile(array $levels, int $status): Profile
    {
        return new Profile('id', ProfileType::Http, new \DateTimeImmutable('2026-06-10T14:00:00+00:00'), 'GET /x', $status, 182.4, 24.1, QueryStats::empty(), $levels, [new LogRecord('info', 'app', 'boom', [], 1.0)], null, false, 'GET', 18.0, 24.0);
    }
}

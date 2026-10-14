<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Tests\Core\Storage;

use Aleblanc\LogUi\Core\Model\LogRecord;
use Aleblanc\LogUi\Core\Model\Profile;
use Aleblanc\LogUi\Core\Model\ProfileType;
use Aleblanc\LogUi\Core\Model\QueryStats;
use Aleblanc\LogUi\Core\Storage\PlainLogReader;
use Aleblanc\LogUi\Core\Storage\TelemetryReader;
use Aleblanc\LogUi\Core\Time\TimestampParser;
use PHPUnit\Framework\TestCase;

/**
 * A telemetry line is a record like any other: a request or a command, listed under its label.
 *
 * @phpstan-import-type Row from PlainLogReader
 */
final class PlainLogReaderTelemetryTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'logui_tele_').'.log';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    private function profile(string $id, string $type, string $label, ?int $status): Profile
    {
        return new Profile(
            $id,
            ProfileType::from($type),
            new \DateTimeImmutable('2026-10-13T12:00:05+00:00'),
            $label,
            $status,
            12.5,
            3.0,
            new QueryStats(2, []),
            ['error' => 1],
            [new LogRecord('error', 'payment', 'Payment declined', [], 1.0)],
            null,
            false,
            'http' === $type ? 'POST' : null,
        );
    }

    private function payload(Profile $profile): string
    {
        return (string) json_encode($profile->toArray(), \JSON_UNESCAPED_SLASHES);
    }

    /** @return list<Row> */
    private function rows(): array
    {
        return iterator_to_array((new PlainLogReader(new TimestampParser('UTC')))->read($this->file), false);
    }

    public function test_a_direct_telemetry_line_becomes_a_request_row(): void
    {
        $payload = $this->payload($this->profile('r1', 'http', 'POST app_checkout', 200));
        file_put_contents($this->file, TelemetryReader::SENTINEL.$payload."\n");

        $rows = $this->rows();

        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertSame('request', $row['type']);
        self::assertSame('POST app_checkout', $row['message']);
        self::assertSame('error', $row['level'], 'worst record level');
        self::assertSame('', $row['channel']);
        self::assertSame(strtotime('2026-10-13T12:00:05+00:00') * 1000, $row['ts']);
        self::assertSame($payload, $row['context'], 'the whole payload stays searchable');
        self::assertNotNull($row['profile']);
        self::assertSame('r1', $row['profile']['id']);
        self::assertSame('POST', $row['profile']['method']);
    }

    public function test_a_command_line_becomes_a_command_row(): void
    {
        file_put_contents($this->file, TelemetryReader::SENTINEL.$this->payload($this->profile('c1', 'cli', 'app:import', 1))."\n");

        $row = $this->rows()[0];

        self::assertSame('command', $row['type']);
        self::assertNotNull($row['profile']);
        self::assertTrue($row['profile']['failed'], 'exit code 1');
        self::assertSame('error', $row['level']);
    }

    public function test_a_monolog_wrapped_telemetry_line_is_a_profile_row_too(): void
    {
        $payload = $this->payload($this->profile('r2', 'http', 'GET app_home', 200));
        file_put_contents($this->file, '[2026-10-13T12:00:05+00:00] app.INFO: '.TelemetryReader::SENTINEL.$payload." []\n");

        $row = $this->rows()[0];

        self::assertSame('request', $row['type']);
        self::assertNotNull($row['profile']);
        self::assertSame('r2', $row['profile']['id']);
        self::assertSame('GET app_home', $row['message']);
    }

    public function test_ordinary_lines_are_log_rows_with_no_profile(): void
    {
        // The raw line goes FIRST: after a Monolog record it would be read as that record's
        // continuation (a stack trace), not as a row of its own.
        file_put_contents($this->file, "raw line\n[2026-10-13T12:00:05+00:00] app.INFO: hello\n");

        $rows = $this->rows();

        self::assertCount(2, $rows);
        self::assertSame('log', $rows[0]['type']);
        self::assertNull($rows[0]['profile']);
        self::assertSame('log', $rows[1]['type']);
        self::assertNull($rows[1]['profile']);
    }

    public function test_a_broken_telemetry_line_falls_through_to_a_raw_row(): void
    {
        file_put_contents($this->file, TelemetryReader::SENTINEL."{not json\n");

        $rows = $this->rows();

        self::assertCount(1, $rows);
        self::assertSame('log', $rows[0]['type']);
        self::assertStringStartsWith('LOGUI@', $rows[0]['message']);
    }
}

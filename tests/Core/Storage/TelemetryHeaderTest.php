<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Tests\Core\Storage;

use Aleblanc\LogUi\Core\Model\LogRecord;
use Aleblanc\LogUi\Core\Model\Profile;
use Aleblanc\LogUi\Core\Model\ProfileType;
use Aleblanc\LogUi\Core\Model\QueryStats;
use Aleblanc\LogUi\Core\Storage\TelemetryHeader;
use PHPUnit\Framework\TestCase;

final class TelemetryHeaderTest extends TestCase
{
    /** @param array<string,int> $levels */
    private function profile(?int $status = 200, string $type = 'http', array $levels = ['debug' => 3, 'warning' => 1]): Profile
    {
        return new Profile(
            'abc123',
            ProfileType::from($type),
            new \DateTimeImmutable('2026-10-13T12:00:00+00:00'),
            'GET app_home',
            $status,
            83.8,
            4.0,
            new QueryStats(3, [['sql' => 'SELECT 1', 'ms' => 91.2]]),
            $levels,
            [new LogRecord('warning', 'app', 'careful {"status":500}', ['status' => 500], 1.0)],
            null,
            false,
            'GET',
            2.0,
            3.5,
        );
    }

    /** @return string the JSON payload as the logger writes it */
    private function payload(Profile $profile): string
    {
        return (string) json_encode($profile->toArray(), \JSON_UNESCAPED_SLASHES);
    }

    public function test_records_are_written_last_so_the_header_can_be_cut_cheaply(): void
    {
        $keys = array_keys($this->profile()->toArray());

        self::assertSame('records', end($keys));
        self::assertContains('truncated', $keys);
    }

    public function test_extracts_the_header_without_the_records(): void
    {
        $header = TelemetryHeader::extract($this->payload($this->profile()));

        self::assertNotNull($header);
        self::assertSame('abc123', $header['id']);
        self::assertSame('http', $header['type']);
        self::assertSame('2026-10-13T12:00:00+00:00', $header['at']);
        self::assertSame('GET app_home', $header['label']);
        self::assertSame('GET', $header['method']);
        self::assertSame(200, $header['status']);
        self::assertSame(83.8, $header['duration_ms']);
        self::assertSame(4.0, $header['mem_peak_mb']);
        self::assertSame(3, $header['sql']);
        self::assertSame(1, $header['slow']);
        self::assertSame(['debug' => 3, 'warning' => 1], $header['levels']);
        self::assertFalse($header['exception']);
        self::assertFalse($header['failed']);
        self::assertArrayNotHasKey('records', $header);
    }

    public function test_a_status_inside_a_record_does_not_leak_into_the_header(): void
    {
        // The record's context says status 500; the request itself answered 200.
        $header = TelemetryHeader::extract($this->payload($this->profile()));

        self::assertNotNull($header);
        self::assertSame(200, $header['status']);
    }

    public function test_falls_back_to_a_full_decode_for_lines_written_before_records_moved_last(): void
    {
        // Old order: method and truncated came AFTER records.
        $old = '{"id":"old1","type":"http","at":"2026-10-13T12:00:00+00:00","label":"GET /x","status":200,'
            .'"duration_ms":1.5,"mem_peak_mb":2,"queries":{"count":0,"slow":[]},"levels":{},'
            .'"records":[{"level":"info","channel":"app","msg":"hi","context":[],"t":0.1}],'
            .'"exception":null,"truncated":false,"method":"POST"}';

        $header = TelemetryHeader::extract($old);

        self::assertNotNull($header);
        self::assertSame('POST', $header['method'], 'keys after records are recovered by the fallback');
    }

    public function test_tolerates_trailing_text_after_the_json(): void
    {
        $header = TelemetryHeader::extract($this->payload($this->profile()).' []');

        self::assertNotNull($header);
        self::assertSame('abc123', $header['id']);
    }

    public function test_rejects_payloads_that_are_not_a_profile(): void
    {
        self::assertNull(TelemetryHeader::extract('{"foo":1}'));
        self::assertNull(TelemetryHeader::extract('not json'));
    }

    public function test_failed_is_5xx_for_http_and_non_zero_exit_for_cli(): void
    {
        self::assertTrue(TelemetryHeader::failed('http', 503));
        self::assertFalse(TelemetryHeader::failed('http', 404));
        self::assertTrue(TelemetryHeader::failed('cli', 1));
        self::assertFalse(TelemetryHeader::failed('cli', 0));
        self::assertFalse(TelemetryHeader::failed('http', null));
    }

    public function test_level_is_the_worst_record_level_raised_to_error_when_failed(): void
    {
        $ok = TelemetryHeader::extract($this->payload($this->profile(200)));
        self::assertNotNull($ok);
        self::assertSame('warning', TelemetryHeader::level($ok));

        $failed = TelemetryHeader::extract($this->payload($this->profile(500)));
        self::assertNotNull($failed);
        self::assertSame('error', TelemetryHeader::level($failed));

        $critical = TelemetryHeader::extract($this->payload($this->profile(500, 'http', ['critical' => 1])));
        self::assertNotNull($critical);
        self::assertSame('critical', TelemetryHeader::level($critical), 'a worse level is kept');

        $quiet = TelemetryHeader::extract($this->payload($this->profile(200, 'http', [])));
        self::assertNotNull($quiet);
        self::assertSame('info', TelemetryHeader::level($quiet));
    }

    public function test_row_type_maps_profile_types(): void
    {
        self::assertSame('request', TelemetryHeader::rowType('http'));
        self::assertSame('command', TelemetryHeader::rowType('cli'));
    }

    public function test_the_compact_header_is_read_without_the_bulk_that_follows(): void
    {
        $slow = [];
        for ($i = 0; $i < 200; ++$i) {
            $slow[] = ['sql' => 'SELECT * FROM ad WHERE id = '.$i.' -- "queries": decoy', 'ms' => 60.0 + $i];
        }
        $profile = new Profile(
            'abc123', ProfileType::Http, new \DateTimeImmutable('2026-10-13T12:00:00+00:00'), 'GET app_home', 500, 83.8, 4.0,
            new QueryStats(250, $slow), ['error' => 1], [], ['class' => 'RuntimeException', 'message' => 'boom', 'trace' => str_repeat('#0 frame'."\n", 500)], false,
        );
        $payload = json_encode($profile->toArray(), \JSON_UNESCAPED_SLASHES);

        self::assertLessThan(500, strpos($payload, ',"queries":'), 'the header comes first');
        $header = TelemetryHeader::extract($payload);

        self::assertNotNull($header);
        self::assertSame(250, $header['sql']);
        self::assertSame(200, $header['slow']);
        self::assertTrue($header['exception']);
        self::assertTrue($header['failed']);
        self::assertSame(['error' => 1], $header['levels']);
    }

    public function test_lines_written_before_the_compact_header_still_read(): void
    {
        // `records` last, but no `sql_count`: the previous layout.
        $payload = '{"id":"old1","type":"http","at":"2026-10-13T12:00:00+00:00","label":"GET x","method":"GET","status":200,"duration_ms":12.5,"mem_peak_mb":3.0,'
            .'"queries":{"count":4,"slow":[{"sql":"SELECT 1","ms":80}]},"levels":{"info":2},"exception":null,"truncated":false,"records":[]}';

        $header = TelemetryHeader::extract($payload);

        self::assertNotNull($header);
        self::assertSame(4, $header['sql']);
        self::assertSame(1, $header['slow']);
        self::assertFalse($header['exception']);
    }
}

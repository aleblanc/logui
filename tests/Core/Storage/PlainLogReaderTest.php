<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Tests\Core\Storage;

use Aleblanc\LogUi\Core\Storage\PlainLogReader;
use PHPUnit\Framework\TestCase;

final class PlainLogReaderTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir().'/logui-plain-'.uniqid().'.log';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    public function test_read_tail_only_reads_the_end_of_a_large_file(): void
    {
        $lines = [];
        for ($i = 1; $i <= 200; ++$i) {
            $lines[] = sprintf('[2026-06-10T14:%02d:%02d+00:00] app.INFO: line-%d []', $i % 60, $i % 60, $i);
        }
        file_put_contents($this->file, implode("\n", $lines)."\n");

        // ~300 bytes from the end ≈ only the last handful of ~50-byte lines.
        $rows = iterator_to_array((new PlainLogReader())->readTail($this->file, 300));
        $messages = array_map(static fn (array $r): string => $r['message'], $rows);

        self::assertNotEmpty($rows);
        self::assertContains('line-200', $messages, 'the last entry is present');
        self::assertNotContains('line-1', $messages, 'early entries are not read');
    }

    public function test_read_tail_reads_whole_small_file(): void
    {
        file_put_contents($this->file, '[2026-06-10T14:03:11+00:00] app.ERROR: Only []'."\n");

        $rows = iterator_to_array((new PlainLogReader())->readTail($this->file, 1_000_000));

        self::assertCount(1, $rows);
        self::assertSame('Only', $rows[0]['message']);
    }

    public function test_parses_standard_monolog_lines(): void
    {
        file_put_contents(
            $this->file,
            '[2026-06-10T14:03:11+00:00] app.ERROR: Boom {"id":7} []'."\n"
            .'[2026-06-10T14:03:12+00:00] request.INFO: Matched route {"route":"home"} {"x":1}'."\n",
        );

        $rows = iterator_to_array((new PlainLogReader())->read($this->file));

        self::assertCount(2, $rows);
        self::assertSame('app', $rows[0]['channel']);
        self::assertSame('error', $rows[0]['level']);
        self::assertSame('Boom', $rows[0]['message']);
        self::assertSame('{"id":7}', $rows[0]['context']);
        self::assertSame('request', $rows[1]['channel']);
    }

    public function test_appends_continuation_lines_to_previous_message(): void
    {
        file_put_contents(
            $this->file,
            '[2026-06-10T14:03:11+00:00] app.CRITICAL: Stack trace follows'."\n"
            .'#0 /app/src/Foo.php(12)'."\n"
            .'#1 {main}'."\n",
        );

        $rows = iterator_to_array((new PlainLogReader())->read($this->file));

        self::assertCount(1, $rows);
        self::assertStringContainsString('#0 /app/src/Foo.php(12)', $rows[0]['message']);
    }

    public function test_missing_file_yields_nothing(): void
    {
        self::assertSame([], iterator_to_array((new PlainLogReader())->read('/no/such/file.log')));
    }

    public function test_parses_symfony_console_handler_lines(): void
    {
        file_put_contents(
            $this->file,
            '21:00:17 WARNING   [weather] WeatherService refresh [metz]: Idle timeout reached for "https://api.open-meteo.com/v1/forecast?x=1".'."\n"
            .'21:00:18 INFO      [app] all good'."\n"
            .' ----------- -------- '."\n"          // a console table border …
            .'  metz        ERREUR  '."\n"          // … and a table row → stay raw, not misparsed
        );

        $rows = iterator_to_array((new PlainLogReader())->read($this->file));

        self::assertSame('21:00:17', $rows[0]['date']);
        self::assertSame('weather', $rows[0]['channel'], 'channel is the first [..], not the [metz] inside the message');
        self::assertSame('warning', $rows[0]['level']);
        self::assertStringContainsString('Idle timeout reached', $rows[0]['message']);

        self::assertSame('app', $rows[1]['channel']);
        self::assertSame('info', $rows[1]['level']);

        // Table decoration is not a log record → kept raw, no level (so it's not coloured/filtered as one).
        self::assertSame('', $rows[2]['level']);
        self::assertSame('', $rows[3]['level']);
    }

    public function test_parses_monolog_json_formatter_lines(): void
    {
        file_put_contents(
            $this->file,
            '{"message":"Schedule run end","context":{"minute":"2026-06-01 23:45","count":3},"level":200,"level_name":"INFO","channel":"cron_scheduler","datetime":"2026-06-01T23:45:23.994250+02:00","extra":{}}'."\n"
            .'{"message":"Idle timeout reached","context":{},"level":300,"level_name":"WARNING","channel":"weather","datetime":"2026-06-01T23:50:12.998633+02:00","extra":{}}'."\n"
        );

        $rows = iterator_to_array((new PlainLogReader())->read($this->file));

        self::assertCount(2, $rows);
        self::assertSame('cron_scheduler', $rows[0]['channel']);
        self::assertSame('info', $rows[0]['level']);
        self::assertSame('Schedule run end', $rows[0]['message']);
        self::assertSame('2026-06-01T23:45:23.994250+02:00', $rows[0]['date']);
        self::assertSame('{"minute":"2026-06-01 23:45","count":3}', $rows[0]['context'], 'context is re-encoded compactly');

        self::assertSame('weather', $rows[1]['channel']);
        self::assertSame('warning', $rows[1]['level']);
        self::assertNull($rows[1]['context'], 'empty context {} becomes null (not shown)');
    }

    public function test_parses_logstash_formatter_lines(): void
    {
        // LogstashFormatter: date is @timestamp, level is the name under "level", no "level_name".
        file_put_contents(
            $this->file,
            '{"@timestamp":"2026-06-01T23:45:23.994250+02:00","@version":1,"host":"h","message":"run end","type":"cron","channel":"cron_scheduler","level":"WARNING","monolog_level":300,"extra":{"x":1},"context":{"count":3}}'."\n"
        );

        $rows = iterator_to_array((new PlainLogReader())->read($this->file));

        self::assertSame('cron_scheduler', $rows[0]['channel']);
        self::assertSame('warning', $rows[0]['level'], 'level resolved from the "level" name field');
        self::assertSame('2026-06-01T23:45:23.994250+02:00', $rows[0]['date'], 'date resolved from @timestamp');
        self::assertSame('{"count":3}', $rows[0]['context']);
    }

    public function test_parses_google_cloud_logging_formatter_lines(): void
    {
        // GoogleCloudLoggingFormatter: level under "severity", date under "time", no level_name/datetime.
        file_put_contents(
            $this->file,
            '{"message":"boot","channel":"app","severity":"ERROR","time":"2026-06-01T23:45:23.994250Z","context":{},"extra":{}}'."\n"
        );

        $rows = iterator_to_array((new PlainLogReader())->read($this->file));

        self::assertSame('app', $rows[0]['channel']);
        self::assertSame('error', $rows[0]['level'], 'level resolved from "severity"');
        self::assertSame('2026-06-01T23:45:23.994250Z', $rows[0]['date'], 'date resolved from "time"');
    }

    public function test_parses_numeric_only_json_level(): void
    {
        file_put_contents(
            $this->file,
            '{"message":"x","channel":"app","level":400,"datetime":"2026-06-01T00:00:00+00:00","context":{},"extra":{}}'."\n"
        );

        $rows = iterator_to_array((new PlainLogReader())->read($this->file));

        self::assertSame('error', $rows[0]['level'], 'numeric level 400 → error');
    }

    public function test_non_log_json_falls_through_to_raw(): void
    {
        // A JSON line that isn't a Monolog record must stay raw, not be mis-parsed.
        file_put_contents($this->file, '{"foo":"bar","baz":1}'."\n");

        $rows = iterator_to_array((new PlainLogReader())->read($this->file));

        self::assertCount(1, $rows);
        self::assertSame('', $rows[0]['level']);
        self::assertSame('{"foo":"bar","baz":1}', $rows[0]['message']);
    }

    public function test_strips_ansi_colour_escapes(): void
    {
        file_put_contents(
            $this->file,
            "21:00:17 \e[33mWARNING\e[39m   [weather] \e[32mtimeout\e[39m".PHP_EOL,
        );

        $rows = iterator_to_array((new PlainLogReader())->read($this->file));

        self::assertSame('warning', $rows[0]['level']);
        self::assertSame('weather', $rows[0]['channel']);
        self::assertSame('timeout', $rows[0]['message'], 'colour codes are removed from the message');
    }

    public function test_parses_nginx_access_lines(): void
    {
        file_put_contents(
            $this->file,
            '203.0.113.10 - - [06/Jun/2026:17:07:19 +0200] "GET / HTTP/1.1" 302 282 "-" "Firefox"'."\n"
            .'203.0.113.20 - - [06/Jun/2026:17:07:25 +0200] "GET /boom HTTP/1.1" 500 99 "-" "curl"'."\n"
        );

        $rows = iterator_to_array((new PlainLogReader())->read($this->file));

        self::assertCount(2, $rows);
        self::assertSame('access', $rows[0]['channel']);
        self::assertSame('info', $rows[0]['level'], '302 → info');
        self::assertStringContainsString('GET /', $rows[0]['message']);
        self::assertSame('error', $rows[1]['level'], '500 → error');
    }

    public function test_parses_nginx_error_lines(): void
    {
        file_put_contents(
            $this->file,
            '2026/06/06 18:05:40 [error] 335981#335981: *954 FastCGI sent in stderr: "boom"'."\n"
        );

        $rows = iterator_to_array((new PlainLogReader())->read($this->file));

        self::assertCount(1, $rows);
        self::assertSame('error', $rows[0]['level']);
        self::assertStringContainsString('FastCGI', $rows[0]['message']);
    }

    public function test_unstructured_lines_are_kept_as_raw_not_dropped(): void
    {
        file_put_contents($this->file, "boot sequence started\nall systems go\n");

        $rows = iterator_to_array((new PlainLogReader())->read($this->file));

        self::assertCount(2, $rows);
        self::assertSame('boot sequence started', $rows[0]['message']);
        self::assertSame('', $rows[0]['level']);
    }

    public function test_parses_channel_containing_a_dot(): void
    {
        file_put_contents($this->file, '[2026-06-10T14:03:11+00:00] doctrine.orm.DEBUG: SELECT 1 []'."\n");

        $rows = iterator_to_array((new PlainLogReader())->read($this->file));

        self::assertSame('doctrine.orm', $rows[0]['channel']);
        self::assertSame('debug', $rows[0]['level']);
    }
}

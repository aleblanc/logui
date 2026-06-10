<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Tests\Core\Storage;

use Aleblanc\LogUi\Core\Model\Profile;
use Aleblanc\LogUi\Core\Model\ProfileType;
use Aleblanc\LogUi\Core\Model\QueryStats;
use Aleblanc\LogUi\Core\Storage\TelemetryReader;
use PHPUnit\Framework\TestCase;

final class TelemetryReaderTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir().'/logui-telemetry-'.uniqid().'.log';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    public function test_reads_telemetry_lines_and_ignores_the_rest(): void
    {
        $profile = new Profile('abc', ProfileType::Http, new \DateTimeImmutable('2026-06-10T14:00:00+00:00'), 'GET /checkout', 200, 182.4, 24.1, new QueryStats(12, []), ['error' => 1], [], null, false, 'GET', 18.0, 24.0);
        $json = (string) json_encode($profile->toArray(), \JSON_UNESCAPED_SLASHES);

        file_put_contents(
            $this->file,
            '[2026-06-10T14:03:11+00:00] app.INFO: a normal log line {"x":1} []'."\n"
            .'[2026-06-10T14:03:12+00:00] app.INFO: LOGUI@'.$json.' []'."\n"
        );

        $profiles = (new TelemetryReader())->read($this->file);

        self::assertCount(1, $profiles);
        self::assertSame('abc', $profiles[0]->id);
        self::assertSame('GET /checkout', $profiles[0]->label);
        self::assertSame('GET', $profiles[0]->method);
        self::assertSame(182.4, $profiles[0]->durationMs);
        self::assertSame(12, $profiles[0]->queries->count);
        self::assertSame(['error' => 1], $profiles[0]->levels);
        self::assertSame(18.0, $profiles[0]->memStartMb);
        self::assertSame(24.0, $profiles[0]->memEndMb);
    }

    public function test_missing_file_returns_empty(): void
    {
        self::assertSame([], (new TelemetryReader())->read('/no/such.log'));
    }
}

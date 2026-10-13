<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Tests\Core\Storage;

use Aleblanc\LogUi\Core\Storage\TelemetryFiles;
use PHPUnit\Framework\TestCase;

/**
 * This class deletes files, so most of what follows is about what it must **not** delete.
 */
final class TelemetryFilesTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/logui_files_'.bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($this->dir);
    }

    private function pattern(): string
    {
        return $this->dir.'/logui.log';
    }

    private function touchFile(string $name): string
    {
        $path = $this->dir.'/'.$name;
        file_put_contents($path, "x\n");

        return $path;
    }

    /** @return list<string> */
    private function remaining(): array
    {
        $names = array_map(basename(...), glob($this->dir.'/*') ?: []);
        sort($names);

        return $names;
    }

    // ---------------------------------------------------------------- naming

    public function test_without_rotation_nothing_changes(): void
    {
        $files = new TelemetryFiles($this->pattern());

        self::assertFalse($files->rotates());
        self::assertSame($this->pattern(), $files->current());
        self::assertSame([], $files->prune(), 'never deletes anything');
    }

    public function test_the_date_goes_before_the_extension(): void
    {
        $files = new TelemetryFiles($this->pattern(), TelemetryFiles::DAILY);

        self::assertSame(
            $this->dir.'/logui-2026-10-13.log',
            $files->current(new \DateTimeImmutable('2026-10-13 23:59:59')),
        );
    }

    public function test_monthly_rotation_uses_a_month_stamp(): void
    {
        $files = new TelemetryFiles($this->pattern(), TelemetryFiles::MONTHLY);

        self::assertSame(
            $this->dir.'/logui-2026-10.log',
            $files->current(new \DateTimeImmutable('2026-10-13')),
        );
    }

    public function test_a_pattern_without_an_extension_still_works(): void
    {
        $files = new TelemetryFiles($this->dir.'/logui', TelemetryFiles::DAILY);

        self::assertSame($this->dir.'/logui-2026-10-13', $files->current(new \DateTimeImmutable('2026-10-13')));
    }

    // ---------------------------------------------------------------- listing

    public function test_files_come_back_newest_first(): void
    {
        $this->touchFile('logui-2026-10-11.log');
        $this->touchFile('logui-2026-10-13.log');
        $this->touchFile('logui-2026-10-12.log');

        $files = new TelemetryFiles($this->pattern(), TelemetryFiles::DAILY);

        self::assertSame(
            ['logui-2026-10-13.log', 'logui-2026-10-12.log', 'logui-2026-10-11.log'],
            array_map(basename(...), $files->all()),
        );
    }

    public function test_history_written_before_rotation_was_enabled_is_still_read(): void
    {
        $this->touchFile('logui.log');
        $this->touchFile('logui-2026-10-13.log');

        $files = new TelemetryFiles($this->pattern(), TelemetryFiles::DAILY);

        self::assertSame(
            ['logui-2026-10-13.log', 'logui.log'],
            array_map(basename(...), $files->all()),
            'the undated file is read last — it is the oldest history',
        );
    }

    // ---------------------------------------------------------------- pruning

    public function test_prune_keeps_the_newest_and_deletes_the_rest(): void
    {
        foreach (['09', '10', '11', '12', '13'] as $day) {
            $this->touchFile("logui-2026-10-{$day}.log");
        }

        $removed = (new TelemetryFiles($this->pattern(), TelemetryFiles::DAILY, 3))->prune();

        self::assertCount(2, $removed);
        self::assertSame(
            ['logui-2026-10-11.log', 'logui-2026-10-12.log', 'logui-2026-10-13.log'],
            $this->remaining(),
        );
    }

    public function test_max_files_zero_keeps_everything(): void
    {
        $this->touchFile('logui-2026-10-11.log');
        $this->touchFile('logui-2026-10-13.log');

        self::assertSame([], (new TelemetryFiles($this->pattern(), TelemetryFiles::DAILY, 0))->prune());
        self::assertCount(2, $this->remaining());
    }

    /**
     * The whole point of the round-trip date check. Every name below is close enough to be caught
     * by a naive glob, and none of them is ours.
     */
    public function test_prune_never_touches_a_file_that_is_not_its_own(): void
    {
        $this->touchFile('logui.log');                  // the base file — may be the app's own log
        $this->touchFile('logui-backup.log');           // not a date
        $this->touchFile('logui-2026-13-99.log');       // impossible date
        $this->touchFile('logui-2026-10-13.log.gz');    // already rotated by logrotate
        $this->touchFile('logui-2026-10-1.log');        // not zero-padded, we never write this
        $this->touchFile('prod.log');                   // somebody else entirely
        $this->touchFile('logui-2026-10-13.log');       // ← the only real one
        $this->touchFile('logui-2026-10-12.log');       // ← and this one

        $removed = (new TelemetryFiles($this->pattern(), TelemetryFiles::DAILY, 1))->prune();

        self::assertSame([$this->dir.'/logui-2026-10-12.log'], $removed);
        self::assertSame([
            'logui-2026-10-1.log',
            'logui-2026-10-13.log',
            'logui-2026-10-13.log.gz',
            'logui-2026-13-99.log',
            'logui-backup.log',
            'logui.log',
            'prod.log',
        ], $this->remaining());
    }

    public function test_a_monthly_setup_does_not_eat_daily_files(): void
    {
        $this->touchFile('logui-2026-10-13.log');   // daily shape
        $this->touchFile('logui-2026-09.log');      // monthly
        $this->touchFile('logui-2026-10.log');      // monthly

        $removed = (new TelemetryFiles($this->pattern(), TelemetryFiles::MONTHLY, 1))->prune();

        self::assertSame([$this->dir.'/logui-2026-09.log'], $removed);
        self::assertContains('logui-2026-10-13.log', $this->remaining(), 'a daily file is not a monthly one');
    }
}

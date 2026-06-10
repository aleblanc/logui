<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Tests\Bridge\Symfony\Log;

use Aleblanc\LogUi\Bridge\Symfony\Log\RawLogSources;
use Aleblanc\LogUi\Bridge\Symfony\Monolog\HandlerPathDiscovery;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

final class RawLogSourcesTest extends TestCase
{
    public function test_merges_discovered_and_configured_paths_deduped(): void
    {
        $discovered = sys_get_temp_dir().'/logui-rl-d-'.uniqid().'.log';
        $configured = sys_get_temp_dir().'/logui-rl-c-'.uniqid().'.log';
        $logger = new Logger('app', [new StreamHandler($discovered)]);

        $sources = new RawLogSources(new HandlerPathDiscovery(), [$logger], [$configured, $discovered]);
        $all = $sources->all();
        $paths = array_map(static fn (array $e): string => $e['path'], $all);

        self::assertContains($discovered, $paths);
        self::assertContains($configured, $paths);
        self::assertCount(2, $all, 'the duplicate discovered/configured path is merged');
    }

    public function test_knows_returns_true_only_for_listed_paths(): void
    {
        $configured = sys_get_temp_dir().'/logui-rl-'.uniqid().'.log';
        $sources = new RawLogSources(new HandlerPathDiscovery(), [], [$configured]);

        self::assertTrue($sources->knows($configured));
        self::assertFalse($sources->knows('/etc/passwd'));
    }

    public function test_ref_is_project_relative_and_resolves_back_to_the_path(): void
    {
        $project = sys_get_temp_dir().'/logui-proj-'.uniqid();
        mkdir($project.'/var/log', 0o777, true);
        $inside = $project.'/var/log/app.log';
        touch($inside);

        try {
            $sources = new RawLogSources(new HandlerPathDiscovery(), [], [$inside], projectDir: $project);
            $entry = $sources->all()[0];

            // The public ref is the relative path — the absolute prefix never leaks.
            self::assertSame('var/log/app.log', $entry['ref']);
            self::assertStringNotContainsString($project, $entry['ref']);
            // …and it round-trips back to the real path, only via the allow-list.
            self::assertSame($inside, $sources->resolve('var/log/app.log'));
            self::assertNull($sources->resolve('var/log/app.log/../../../etc/passwd'));
            self::assertNull($sources->resolve('nope.log'));
        } finally {
            unlink($inside);
            rmdir($project.'/var/log');
            rmdir($project.'/var');
            rmdir($project);
        }
    }

    public function test_ref_falls_back_to_basename_outside_the_project(): void
    {
        $outside = sys_get_temp_dir().'/logui-ext-'.uniqid().'.log';
        touch($outside);

        try {
            $sources = new RawLogSources(new HandlerPathDiscovery(), [], [$outside], projectDir: '/some/project');

            self::assertSame(basename($outside), $sources->all()[0]['ref']);
            self::assertSame($outside, $sources->resolve(basename($outside)));
        } finally {
            unlink($outside);
        }
    }

    public function test_discovery_can_be_disabled(): void
    {
        $discovered = sys_get_temp_dir().'/logui-rl-off-'.uniqid().'.log';
        $logger = new Logger('app', [new StreamHandler($discovered)]);

        $sources = new RawLogSources(new HandlerPathDiscovery(), [$logger], [], logDirs: [], discoverMonolog: false);

        self::assertSame([], $sources->all());
    }

    public function test_scans_log_directories_for_log_files(): void
    {
        $dir = sys_get_temp_dir().'/logui-rl-dir-'.uniqid();
        mkdir($dir);
        touch($dir.'/access.log');
        touch($dir.'/error.log');
        touch($dir.'/notes.txt'); // non-.log → ignored

        try {
            $sources = new RawLogSources(new HandlerPathDiscovery(), [], [], logDirs: [$dir]);
            $paths = array_map(static fn (array $e): string => $e['path'], $sources->all());

            self::assertContains($dir.'/access.log', $paths);
            self::assertContains($dir.'/error.log', $paths);
            self::assertNotContains($dir.'/notes.txt', $paths);
            self::assertTrue($sources->knows($dir.'/access.log'));
        } finally {
            array_map('unlink', glob($dir.'/*') ?: []);
            rmdir($dir);
        }
    }

    public function test_scanned_and_configured_paths_are_deduped(): void
    {
        $dir = sys_get_temp_dir().'/logui-rl-dd-'.uniqid();
        mkdir($dir);
        touch($dir.'/app.log');

        try {
            $sources = new RawLogSources(new HandlerPathDiscovery(), [], [$dir.'/app.log'], logDirs: [$dir]);

            self::assertCount(1, $sources->all());
        } finally {
            array_map('unlink', glob($dir.'/*') ?: []);
            rmdir($dir);
        }
    }
}

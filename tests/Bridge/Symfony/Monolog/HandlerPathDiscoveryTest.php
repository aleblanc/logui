<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Tests\Bridge\Symfony\Monolog;

use Aleblanc\LogUi\Bridge\Symfony\Monolog\HandlerPathDiscovery;
use Monolog\Handler\FingersCrossedHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

final class HandlerPathDiscoveryTest extends TestCase
{
    public function test_discovers_stream_file_paths_unwrapping_wrappers(): void
    {
        $file = sys_get_temp_dir().'/logui-disc-'.uniqid().'.log';
        $logger = new Logger('app', [
            new FingersCrossedHandler(new StreamHandler($file)),
            new TestHandler(),                 // not a file handler → ignored
            new StreamHandler('php://stderr'), // not a real file → ignored
        ]);

        $found = (new HandlerPathDiscovery())->discover([$logger]);

        $paths = array_map(static fn (array $e): string => $e['path'], $found);
        self::assertContains($file, $paths);
        self::assertNotContains('php://stderr', $paths);
        self::assertCount(1, $found);
    }

    public function test_dedupes_same_path(): void
    {
        $file = sys_get_temp_dir().'/logui-disc-'.uniqid().'.log';
        $a = new Logger('a', [new StreamHandler($file)]);
        $b = new Logger('b', [new StreamHandler($file)]);

        self::assertCount(1, (new HandlerPathDiscovery())->discover([$a, $b]));
    }
}

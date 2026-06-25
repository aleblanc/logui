<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Tests\Bridge\Symfony\Health;

use Aleblanc\LogUi\Bridge\Symfony\Health\SystemHealthService;
use Aleblanc\LogUi\Bridge\Symfony\Health\VnstatService;
use PHPUnit\Framework\TestCase;

final class SystemHealthServiceTest extends TestCase
{
    public function test_get_data_exposes_all_sections_and_degrades_gracefully(): void
    {
        $data = (new SystemHealthService([], new VnstatService()))->getData();

        foreach (['model', 'uptime', 'temperature', 'throttle', 'load', 'memory', 'disks', 'interfaces', 'docker', 'services', 'processes', 'network_usage'] as $key) {
            self::assertArrayHasKey($key, $data);
        }

        // No services configured => empty section (nothing rendered).
        self::assertSame([], $data['services']);
        // List-type probes are always arrays (possibly empty when the tool is absent).
        self::assertIsArray($data['disks']);
        self::assertIsArray($data['interfaces']);
        self::assertIsArray($data['docker']);
    }
}

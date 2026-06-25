<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Tests\Bridge\Symfony\Controller;

use Aleblanc\LogUi\Bridge\Symfony\Controller\HealthController;
use Aleblanc\LogUi\Bridge\Symfony\Health\HealthProvider;
use Aleblanc\LogUi\Bridge\Symfony\Security\UiAccessGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class HealthControllerTest extends TestCase
{
    /** @param array<string,mixed> $data */
    private function controller(array $data, string $env = 'dev', ?string $password = null): HealthController
    {
        $provider = new class($data) implements HealthProvider {
            /** @param array<string,mixed> $data */
            public function __construct(private readonly array $data)
            {
            }

            public function getData(): array
            {
                return $this->data;
            }
        };

        $loader = new FilesystemLoader();
        $loader->addPath(\dirname(__DIR__, 4).'/templates', 'LogUi');

        return new HealthController($provider, new Environment($loader), new UiAccessGuard($env, $password), '/_logui');
    }

    /** @return array<string,mixed> */
    private function fullData(): array
    {
        return [
            'model' => 'Raspberry Pi 5',
            'uptime' => ['seconds' => 100000, 'human' => '1j 3h 46min'],
            'temperature' => null,
            'throttle' => null,
            'load' => ['1m' => 0.5, '5m' => 0.4, '15m' => 0.3, 'cpu_count' => 4, 'pct_1m' => 12, 'color' => 'success'],
            'memory' => ['total' => '8 GiB', 'used' => '2 GiB', 'available' => '6 GiB', 'cached' => '1 GiB', 'used_pct' => 25, 'color' => 'success'],
            'disks' => [],
            'disk_usage' => null,
            'interfaces' => [['name' => 'eth0', 'state' => 'UP', 'color' => 'success', 'ips' => ['192.168.1.2/24'], 'mac' => 'aa:bb']],
            'docker' => [],
            'services' => [['name' => 'nginx', 'state' => 'active', 'color' => 'success']],
            'processes' => [['pid' => 1234, 'user' => 'www-data', 'cpu' => 42.0, 'mem' => 3.1, 'rss' => '128 MiB', 'command' => 'php-fpm', 'color' => 'warning']],
            'network_usage' => null,
        ];
    }

    public function test_renders_only_available_sections(): void
    {
        $html = (string) $this->controller($this->fullData())->show(Request::create('/_logui/health'))->getContent();

        // Present data is shown.
        self::assertStringContainsString('Raspberry Pi 5', $html);
        self::assertStringContainsString('1j 3h 46min', $html);
        self::assertStringContainsString('Memory', $html);
        self::assertStringContainsString('192.168.1.2/24', $html);
        self::assertStringContainsString('nginx: active', $html);
        self::assertStringContainsString('Top processes', $html);
        self::assertStringContainsString('php-fpm', $html);

        // Absent data renders nothing — no empty cards/sections.
        self::assertStringNotContainsString('Disks', $html);
        self::assertStringNotContainsString('Docker', $html);
        self::assertStringNotContainsString('Network usage', $html);
        self::assertStringNotContainsString('Temperature', $html);
    }

    public function test_docker_shows_cpu_and_memory_when_available(): void
    {
        $data = $this->fullData();
        $data['docker'] = [
            ['name' => 'app', 'image' => 'php:8.4', 'status' => 'Up 2 hours', 'running' => true, 'cpu' => '0.50%', 'mem' => '128MiB / 512MiB'],
        ];

        $html = (string) $this->controller($data)->show(Request::create('/_logui/health'))->getContent();

        self::assertStringContainsString('0.50%', $html);
        self::assertStringContainsString('128MiB / 512MiB', $html);
    }

    public function test_disk_card_is_red_above_85_percent(): void
    {
        $data = $this->fullData();
        $data['disk_usage'] = ['used' => '90 GiB', 'size' => '100 GiB', 'used_pct' => 90, 'color' => 'danger'];

        $html = (string) $this->controller($data)->show(Request::create('/_logui/health'))->getContent();

        self::assertStringContainsString('90 GiB / 100 GiB', $html);
        self::assertStringContainsString('hl-danger', $html);
    }

    public function test_shows_note_when_nothing_is_available(): void
    {
        $empty = [
            'model' => null, 'uptime' => null, 'temperature' => null, 'throttle' => null, 'load' => null,
            'memory' => [], 'disks' => [], 'disk_usage' => null, 'interfaces' => [], 'docker' => [], 'services' => [], 'processes' => [], 'network_usage' => null,
        ];

        $html = (string) $this->controller($empty)->show(Request::create('/_logui/health'))->getContent();

        self::assertStringContainsString('No server health data available', $html);
    }

    public function test_denied_when_guard_refuses(): void
    {
        $response = $this->controller($this->fullData(), 'prod', null)->show(Request::create('/_logui/health'));

        self::assertSame(403, $response->getStatusCode());
    }
}

<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Tests\Bridge\Symfony\Fixtures;

use Aleblanc\LogUi\Bridge\Symfony\LogUiBundle;
use Aleblanc\LogUi\Bridge\Symfony\Monolog\LogUiHandler;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\MonologBundle\MonologBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public function __construct(private readonly string $varDir)
    {
        parent::__construct('test', false);
    }

    public function registerBundles(): iterable
    {
        return [new FrameworkBundle(), new MonologBundle(), new LogUiBundle()];
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'test',
            'test' => true,
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
            'router' => ['utf8' => true],
        ]);
        $container->extension('monolog', [
            'handlers' => [
                'logui' => ['type' => 'service', 'id' => LogUiHandler::class],
                'main' => ['type' => 'stream', 'path' => $this->varDir.'/log/app.log', 'level' => 'debug'],
            ],
        ]);
        $container->extension('log_ui', [
            'telemetry_file' => $this->varDir.'/log/app.log',
        ]);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import(\dirname(__DIR__, 4).'/config/routes.php');
    }

    public function getProjectDir(): string
    {
        return $this->varDir;
    }

    public function getCacheDir(): string
    {
        return $this->varDir.'/cache';
    }

    public function getLogDir(): string
    {
        return $this->varDir.'/log';
    }
}

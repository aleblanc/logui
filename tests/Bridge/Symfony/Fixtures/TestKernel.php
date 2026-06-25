<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Tests\Bridge\Symfony\Fixtures;

use Aleblanc\LogUi\Bridge\Symfony\LogUiBundle;
use Aleblanc\LogUi\Bridge\Symfony\Monolog\LogUiHandler;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\MonologBundle\MonologBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @param bool      $manualLoguiHandler wire the LogUI handler by hand in monolog config (legacy path);
     *                                      false relies on LogUiBundle::prependExtension() auto-wiring it
     * @param bool|null $captureMonolog     value for log_ui.capture_monolog (null = leave default)
     */
    public function __construct(
        private readonly string $varDir,
        private readonly bool $manualLoguiHandler = false,
        private readonly ?bool $captureMonolog = null,
    ) {
        parent::__construct('test', false);
    }

    public function registerBundles(): iterable
    {
        return [new FrameworkBundle(), new MonologBundle(), new TwigBundle(), new LogUiBundle()];
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
        $container->extension('twig', ['default_path' => $this->varDir.'/templates']);
        $handlers = ['main' => ['type' => 'stream', 'path' => $this->varDir.'/log/app.log', 'level' => 'debug']];
        if ($this->manualLoguiHandler) {
            $handlers['logui'] = ['type' => 'service', 'id' => LogUiHandler::class];
        }
        $container->extension('monolog', ['handlers' => $handlers]);

        $logUi = ['telemetry_file' => $this->varDir.'/log/app.log'];
        if (null !== $this->captureMonolog) {
            $logUi['capture_monolog'] = $this->captureMonolog;
        }
        $container->extension('log_ui', $logUi);
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

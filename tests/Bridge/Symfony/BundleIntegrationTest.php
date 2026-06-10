<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Tests\Bridge\Symfony;

use Aleblanc\LogUi\Bridge\Symfony\Monolog\LogUiHandler;
use Aleblanc\LogUi\Tests\Bridge\Symfony\Fixtures\TestKernel;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

final class BundleIntegrationTest extends TestCase
{
    private string $varDir;
    private TestKernel $kernel;

    protected function setUp(): void
    {
        $this->varDir = sys_get_temp_dir().'/logui-it-'.uniqid();
        $this->kernel = new TestKernel($this->varDir);
        $this->kernel->boot();
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
        // Symfony registers an exception handler during handle(); pop it (as KernelTestCase does) so PHPUnit isn't flagged risky.
        restore_exception_handler();
        exec('rm -rf '.escapeshellarg($this->varDir));
    }

    public function test_ui_route_responds(): void
    {
        $response = $this->kernel->handle(Request::create('/_logui', 'GET'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('LogUI', (string) $response->getContent());
    }

    public function test_an_app_request_is_captured_and_visible_in_the_ui(): void
    {
        // Drive a real app request through the kernel. It 404s (no such route), which still flows
        // through kernel.request (profile begins) → kernel.exception (captured) → kernel.terminate (written),
        // and the framework's own Monolog records are mirrored into the profile by LogUiHandler.
        $appRequest = Request::create('/orders/42', 'GET');
        $appResponse = $this->kernel->handle($appRequest);
        $this->kernel->terminate($appRequest, $appResponse);

        // Now read it back through the UI.
        $uiResponse = $this->kernel->handle(Request::create('/_logui', 'GET'));
        $html = (string) $uiResponse->getContent();

        self::assertSame(200, $uiResponse->getStatusCode());
        self::assertStringContainsString('/orders/42', $html, 'the captured request appears in the LogUI list');
    }

    public function test_handler_is_auto_wired_into_monolog_without_manual_config(): void
    {
        // $this->kernel has NO manual logui handler in its monolog config — prependExtension()
        // must have wired it onto the default logger anyway.
        $loguiHandlers = $this->loguiHandlersOf($this->kernel);

        self::assertCount(1, $loguiHandlers, 'the LogUI handler is auto-wired exactly once');
    }

    public function test_manual_handler_is_not_doubled_by_auto_wiring(): void
    {
        $kernel = new TestKernel(sys_get_temp_dir().'/logui-it-dedup-'.uniqid(), manualLoguiHandler: true);
        $kernel->boot();

        try {
            // Host wired it by hand AND the bundle auto-wires — there must still be only one.
            self::assertCount(1, $this->loguiHandlersOf($kernel), 'manual + auto must not double the handler');
        } finally {
            $kernel->shutdown();
            exec('rm -rf '.escapeshellarg($kernel->getProjectDir()));
        }
    }

    /** @return list<LogUiHandler> */
    private function loguiHandlersOf(TestKernel $kernel): array
    {
        $testContainer = $kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $testContainer);
        $logger = $testContainer->get('logger');
        self::assertInstanceOf(Logger::class, $logger);

        return array_values(array_filter(
            $logger->getHandlers(),
            static fn (object $h): bool => $h instanceof LogUiHandler,
        ));
    }
}

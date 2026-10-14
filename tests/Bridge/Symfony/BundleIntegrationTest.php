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

    /** Searching the logs is what people open LogUI for, so the explorer is the landing page. */
    public function test_the_landing_page_is_the_log_explorer(): void
    {
        $html = (string) $this->kernel->handle(Request::create('/_logui', 'GET'))->getContent();

        self::assertStringContainsString('id="explore"', $html);
        self::assertStringContainsString('/_logui/explore', $html, 'the page points at its own JSON endpoints');
    }

    public function test_the_explorer_knows_the_type_facet(): void
    {
        $html = (string) $this->kernel->handle(Request::create('/_logui', 'GET'))->getContent();

        self::assertStringContainsString('data-types=""', $html);
        self::assertStringContainsString("key: 'type'", $html, 'the fields panel lists the type facet');
        self::assertStringContainsString('/profile?', $html, 'expanding a request fetches its capture');
    }

    public function test_the_explorer_offers_the_request_pivots(): void
    {
        $html = (string) $this->kernel->handle(Request::create('/_logui', 'GET'))->getContent();

        self::assertStringContainsString('around this moment', $html);
        self::assertStringContainsString('open request', $html);
        self::assertStringContainsString('logs of this request', $html);
    }

    public function test_the_explorer_draws_a_request_timeline(): void
    {
        $html = (string) $this->kernel->handle(Request::create('/_logui', 'GET'))->getContent();

        self::assertStringContainsString('function timelineNode', $html);
        self::assertStringContainsString('.ex-tl', $html);
    }

    public function test_the_histogram_has_a_latency_line(): void
    {
        $html = (string) $this->kernel->handle(Request::create('/_logui', 'GET'))->getContent();

        self::assertStringContainsString('function drawLatency', $html);
    }

    public function test_the_explorer_can_compare_with_the_previous_period(): void
    {
        $html = (string) $this->kernel->handle(Request::create('/_logui', 'GET'))->getContent();

        self::assertStringContainsString('id="ex-compare"', $html);
    }

    public function test_the_explorer_renders_readable_stack_traces(): void
    {
        $html = (string) $this->kernel->handle(Request::create('/_logui', 'GET'))->getContent();

        self::assertStringContainsString('function traceNode', $html);
    }

    public function test_the_explorer_has_a_views_panel(): void
    {
        $html = (string) $this->kernel->handle(Request::create('/_logui', 'GET'))->getContent();

        self::assertStringContainsString('id="ex-views"', $html);
    }

    public function test_the_explorer_has_a_keyboard_cheat_sheet(): void
    {
        $html = (string) $this->kernel->handle(Request::create('/_logui', 'GET'))->getContent();

        self::assertStringContainsString('id="ex-keys"', $html);
    }

    public function test_the_old_request_list_url_redirects_to_the_explorer_preset(): void
    {
        $response = $this->kernel->handle(Request::create('/_logui/requests', 'GET'));

        self::assertSame(302, $response->getStatusCode());
        self::assertStringContainsString('types%5B%5D=request', (string) $response->headers->get('Location'));
    }

    public function test_an_app_request_is_captured_and_visible_in_the_explorer(): void
    {
        $appRequest = Request::create('/orders/42', 'GET');
        $appResponse = $this->kernel->handle($appRequest);
        $this->kernel->terminate($appRequest, $appResponse);

        // The explorer's JSON endpoint, filtered to requests, over the default last hour.
        $uiResponse = $this->kernel->handle(Request::create('/_logui/explore/query', 'GET', ['types' => ['request']]));
        $body = json_decode((string) $uiResponse->getContent(), true);

        self::assertSame(200, $uiResponse->getStatusCode());
        self::assertSame(1, $body['total'], 'one captured request');
        self::assertSame('GET /orders/42', $body['rows'][0]['message']);
        self::assertSame('request', $body['rows'][0]['type']);
    }

    public function test_every_record_is_stamped_with_the_id_of_its_request(): void
    {
        $appRequest = Request::create('/orders/42', 'GET');
        $appResponse = $this->kernel->handle($appRequest);
        $this->kernel->terminate($appRequest, $appResponse);

        $log = (string) file_get_contents($this->varDir.'/log/app.log');
        self::assertMatchesRegularExpression('/"logui_id":"[0-9a-f]{16}"/', $log);

        $uiResponse = $this->kernel->handle(Request::create('/_logui/explore/query', 'GET', ['types' => ['request']]));
        $body = json_decode((string) $uiResponse->getContent(), true);
        $id = $body['rows'][0]['profile']['id'];

        self::assertStringContainsString('"logui_id":"'.$id.'"', $log, 'records carry the id of the request that wrote them');
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

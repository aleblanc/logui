<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Tests\Bridge\Symfony\Controller;

use Aleblanc\LogUi\Bridge\Symfony\Controller\RawLogController;
use Aleblanc\LogUi\Bridge\Symfony\Log\RawLogSources;
use Aleblanc\LogUi\Bridge\Symfony\Monolog\HandlerPathDiscovery;
use Aleblanc\LogUi\Bridge\Symfony\Security\UiAccessGuard;
use Aleblanc\LogUi\Core\Storage\PlainLogReader;
use Aleblanc\LogUi\Core\Ui\Renderer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class RawLogControllerTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir().'/logui-rawctrl-'.uniqid().'.log';
        file_put_contents(
            $this->file,
            '[2026-06-10T14:03:11+00:00] app.ERROR: Boom {"id":7} []'."\n"
            .'[2026-06-10T14:03:12+00:00] request.INFO: Matched route {"route":"home"} []'."\n"
        );
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    private function controller(string $env = 'dev'): RawLogController
    {
        $sources = new RawLogSources(new HandlerPathDiscovery(), [], [$this->file]);

        return new RawLogController(
            $sources,
            new PlainLogReader(),
            new Renderer(\dirname(__DIR__, 4).'/templates/logui'),
            new UiAccessGuard($env, null),
            '/_logui',
        );
    }

    public function test_list_renders_source(): void
    {
        $response = $this->controller()->list(Request::create('/_logui/logs'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString(basename($this->file), (string) $response->getContent());
    }

    public function test_view_renders_parsed_rows_newest_first(): void
    {
        $response = $this->controller()->view(Request::create('/_logui/logs/view?path='.rawurlencode($this->file)));

        $html = (string) $response->getContent();
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Boom', $html);
        self::assertStringContainsString('Matched route', $html);
        // newest first: the INFO (later timestamp) row appears before the ERROR row
        self::assertLessThan(strpos($html, 'Boom'), strpos($html, 'Matched route'));
    }

    public function test_view_rejects_unknown_path(): void
    {
        $response = $this->controller()->view(Request::create('/_logui/logs/view?path=/etc/passwd'));

        self::assertSame(403, $response->getStatusCode());
    }

    public function test_denied_when_guard_refuses(): void
    {
        self::assertSame(403, $this->controller('prod')->list(Request::create('/_logui/logs'))->getStatusCode());
    }
}

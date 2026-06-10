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

    public function test_view_filters_by_level(): void
    {
        $response = $this->controller()->view(Request::create('/_logui/logs/view?path='.rawurlencode($this->file).'&level=ERROR'));

        $html = (string) $response->getContent();
        self::assertStringContainsString('Boom', $html, 'the ERROR line is shown');
        self::assertStringNotContainsString('Matched route', $html, 'the INFO line is filtered out');
    }

    public function test_view_paginates_and_truncates_long_messages(): void
    {
        $lines = [];
        for ($i = 0; $i < 150; ++$i) {
            $lines[] = sprintf('[2026-06-10T14:%02d:%02d+00:00] app.INFO: line-%d []', $i % 60, $i % 60, $i);
        }
        // one very long message that must be truncated with a toggle
        $lines[] = '[2026-06-10T15:00:00+00:00] app.ERROR: '.str_repeat('X', 600).' []';
        file_put_contents($this->file, implode("\n", $lines)."\n");

        $page1 = (string) $this->controller()->view(Request::create('/_logui/logs/view?path='.rawurlencode($this->file)))->getContent();
        self::assertSame(100, substr_count($page1, 'white-space:nowrap'), 'page 1 shows 100 rows');
        self::assertStringContainsString('page 1 / 2', $page1);
        self::assertStringContainsString('msg-toggle', $page1, 'long message gets a "more" toggle');
        self::assertStringContainsString('msg-full', $page1);

        $page2 = (string) $this->controller()->view(Request::create('/_logui/logs/view?path='.rawurlencode($this->file).'&page=2'))->getContent();
        self::assertSame(51, substr_count($page2, 'white-space:nowrap'), 'page 2 shows the remaining 51');
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

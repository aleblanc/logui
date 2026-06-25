<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Tests\Bridge\Symfony\Controller;

use Aleblanc\LogUi\Bridge\Symfony\Controller\RawLogController;
use Aleblanc\LogUi\Bridge\Symfony\Log\RawLogSources;
use Aleblanc\LogUi\Bridge\Symfony\Monolog\HandlerPathDiscovery;
use Aleblanc\LogUi\Bridge\Symfony\Security\UiAccessGuard;
use Aleblanc\LogUi\Core\Storage\PlainLogReader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

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
            self::twig(),
            new UiAccessGuard($env, null),
            '/_logui',
        );
    }

    private static function twig(): Environment
    {
        $loader = new FilesystemLoader();
        $loader->addPath(\dirname(__DIR__, 4).'/templates', 'LogUi');

        return new Environment($loader);
    }

    public function test_list_renders_source(): void
    {
        $response = $this->controller()->list(Request::create('/_logui/logs'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString(basename($this->file), (string) $response->getContent());
    }

    public function test_view_renders_parsed_rows_newest_first(): void
    {
        $ref = basename($this->file);
        $response = $this->controller()->view(Request::create('/_logui/logs/view?ref='.rawurlencode($ref)));

        $html = (string) $response->getContent();
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Boom', $html);
        self::assertStringContainsString('Matched route', $html);
        // newest first: the INFO (later timestamp) row appears before the ERROR row
        self::assertLessThan(strpos($html, 'Boom'), strpos($html, 'Matched route'));
    }

    public function test_view_excludes_logui_telemetry_lines(): void
    {
        // A LogUI telemetry line (LOGUI@{json}) written into the same file must not show here.
        file_put_contents(
            $this->file,
            '[2026-06-10T14:03:13+00:00] app.INFO: LOGUI@{"id":"abc","label":"GET /x","records":[]} []'."\n"
            .'[2026-06-10T14:03:14+00:00] app.INFO: A real message {"k":1} []'."\n",
        );

        $html = (string) $this->controller()->view(
            Request::create('/_logui/logs/view?ref='.rawurlencode(basename($this->file)))
        )->getContent();

        self::assertStringNotContainsString('LOGUI@', $html);
        self::assertStringContainsString('A real message', $html);
    }

    public function test_view_never_leaks_the_absolute_path(): void
    {
        $html = (string) $this->controller()->view(
            Request::create('/_logui/logs/view?ref='.rawurlencode(basename($this->file)))
        )->getContent();

        // The absolute server path must not appear anywhere — not in the heading,
        // not in the hidden field, not in the pager/filter links.
        self::assertStringNotContainsString($this->file, $html);
        self::assertStringNotContainsString(\dirname($this->file), $html);
        self::assertStringContainsString('name="ref"', $html);
    }

    public function test_view_filters_by_level(): void
    {
        $response = $this->controller()->view(Request::create('/_logui/logs/view?ref='.rawurlencode(basename($this->file)).'&level=ERROR'));

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

        $ref = basename($this->file);
        $page1 = (string) $this->controller()->view(Request::create('/_logui/logs/view?ref='.rawurlencode($ref)))->getContent();
        self::assertSame(100, substr_count($page1, 'white-space:nowrap'), 'page 1 shows 100 rows');
        self::assertStringContainsString('page 1 / 2', $page1);
        self::assertStringContainsString('msg-toggle', $page1, 'long message gets a "more" toggle');
        self::assertStringContainsString('msg-full', $page1);

        $page2 = (string) $this->controller()->view(Request::create('/_logui/logs/view?ref='.rawurlencode($ref).'&page=2'))->getContent();
        self::assertSame(51, substr_count($page2, 'white-space:nowrap'), 'page 2 shows the remaining 51');
    }

    public function test_view_rejects_unknown_ref(): void
    {
        // A ref outside the allow-list — and a traversal attempt — both 403.
        self::assertSame(403, $this->controller()->view(Request::create('/_logui/logs/view?ref=nope.log'))->getStatusCode());
        self::assertSame(403, $this->controller()->view(Request::create('/_logui/logs/view?ref='.rawurlencode('../../etc/passwd')))->getStatusCode());
    }

    public function test_denied_when_guard_refuses(): void
    {
        self::assertSame(403, $this->controller('prod')->list(Request::create('/_logui/logs'))->getStatusCode());
    }
}

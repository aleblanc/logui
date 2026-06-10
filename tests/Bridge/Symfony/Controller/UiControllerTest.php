<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Tests\Bridge\Symfony\Controller;

use Aleblanc\LogUi\Bridge\Symfony\Controller\UiController;
use Aleblanc\LogUi\Bridge\Symfony\Security\UiAccessGuard;
use Aleblanc\LogUi\Core\Model\LogRecord;
use Aleblanc\LogUi\Core\Model\Profile;
use Aleblanc\LogUi\Core\Model\ProfileType;
use Aleblanc\LogUi\Core\Model\QueryStats;
use Aleblanc\LogUi\Core\Storage\TelemetryReader;
use Aleblanc\LogUi\Core\Ui\Renderer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class UiControllerTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir().'/logui-ctrl-'.uniqid().'.log';
        $this->seed([
            $this->profile('a', 'GET /checkout', 'error'),
            $this->profile('b', 'GET /home', 'info'),
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    /** @param list<Profile> $profiles */
    private function seed(array $profiles): void
    {
        $lines = array_map(
            static fn (Profile $p): string => '[2026-06-10T14:00:00+00:00] app.INFO: '.TelemetryReader::SENTINEL.json_encode($p->toArray(), \JSON_UNESCAPED_SLASHES).' []',
            $profiles,
        );
        file_put_contents($this->file, implode("\n", $lines)."\n");
    }

    private function controller(string $env = 'dev', ?string $password = null): UiController
    {
        return new UiController(
            new TelemetryReader(),
            $this->file,
            new Renderer(\dirname(__DIR__, 4).'/templates/logui'),
            new UiAccessGuard($env, $password),
            '/_logui',
        );
    }

    private function profile(string $id, string $label, string $level, ?string $method = 'GET'): Profile
    {
        return new Profile($id, ProfileType::Http, new \DateTimeImmutable('2026-06-10T14:00:00+00:00'), $label, 200, 1.0, 1.0, QueryStats::empty(), [$level => 1], [new LogRecord($level, 'app', 'msg', [], 0.0)], null, false, $method);
    }

    public function test_list_renders_captured_profiles(): void
    {
        $response = $this->controller()->list(Request::create('/_logui'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('GET /checkout', (string) $response->getContent());
        self::assertStringContainsString('GET /home', (string) $response->getContent());
    }

    public function test_list_filters_by_level(): void
    {
        $response = $this->controller()->list(Request::create('/_logui?level=error'));

        $html = (string) $response->getContent();
        self::assertStringContainsString('GET /checkout', $html);
        self::assertStringNotContainsString('GET /home', $html);
    }

    public function test_filters_by_http_method(): void
    {
        $this->seed([
            $this->profile('a', 'POST /orders', 'info', 'POST'),
            $this->profile('b', 'GET /home', 'info', 'GET'),
        ]);

        $html = (string) $this->controller()->list(Request::create('/_logui?method=POST'))->getContent();

        self::assertStringContainsString('POST /orders', $html);
        self::assertStringNotContainsString('GET /home', $html);
    }

    public function test_stats_header_stays_general_under_a_filter(): void
    {
        // 2 http profiles (1 error, 1 info). Filter to CLI → list is empty, but the header still counts all.
        $html = (string) $this->controller()->list(Request::create('/_logui?type=cli'))->getContent();

        self::assertStringContainsString('No profiles captured yet', $html, 'no CLI rows match');
        self::assertStringContainsString('<b>2</b> profils', $html, 'header total is general');
        self::assertStringContainsString('<b>2</b> requêtes', $html, 'header still counts the 2 http requests');
        self::assertStringContainsString('<b>1</b> error', $html, 'header still counts the error');
    }

    public function test_detail_renders_one_profile(): void
    {
        $response = $this->controller()->detail('a', Request::create('/_logui/a'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('GET /checkout', (string) $response->getContent());
    }

    public function test_detail_shows_the_records_embedded_in_the_telemetry_line(): void
    {
        $profile = new Profile('a', ProfileType::Http, new \DateTimeImmutable('2026-06-10T14:00:00+00:00'), 'GET /checkout', 200, 1.0, 1.0, QueryStats::empty(), ['error' => 1], [
            new LogRecord('info', 'app', 'Cart loaded', [], 1.0),
            new LogRecord('error', 'payment', 'Payment declined', [], 2.0),
        ], null, false, 'GET');
        $this->seed([$profile]);

        $html = (string) $this->controller()->detail('a', Request::create('/_logui/a'))->getContent();

        self::assertStringContainsString('Log records (2)', $html);
        self::assertStringContainsString('Cart loaded', $html);
        self::assertStringContainsString('Payment declined', $html);
    }

    public function test_detail_404_for_unknown_id(): void
    {
        self::assertSame(404, $this->controller()->detail('zzz', Request::create('/_logui/zzz'))->getStatusCode());
    }

    public function test_denied_when_guard_refuses(): void
    {
        self::assertSame(403, $this->controller('prod', null)->list(Request::create('/_logui'))->getStatusCode());
    }

    public function test_stats_render_as_filter_links(): void
    {
        $html = (string) $this->controller()->list(Request::create('/_logui'))->getContent();

        self::assertStringContainsString('class="stat', $html);
        self::assertStringContainsString('type=http', $html);
        self::assertStringContainsString('type=cli', $html);
        self::assertStringContainsString('level=error', $html);
    }

    public function test_paginates_100_per_page_with_summary(): void
    {
        $profiles = [];
        for ($i = 0; $i < 150; ++$i) {
            $profiles[] = $this->profile('id'.$i, 'GET /p'.$i, 'error');
        }
        $this->seed($profiles);

        $page1 = (string) $this->controller()->list(Request::create('/_logui'))->getContent();
        self::assertSame(100, substr_count($page1, '/_logui/id'), 'page 1 shows 100 detail-row links');
        self::assertStringContainsString('page 1 / 2', $page1);
        self::assertStringContainsString('150 profils', $page1);

        $page2 = (string) $this->controller()->list(Request::create('/_logui?page=2'))->getContent();
        self::assertSame(50, substr_count($page2, '/_logui/id'), 'page 2 shows the remaining 50');
    }
}

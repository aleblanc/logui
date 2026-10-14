<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Tests\Bridge\Symfony\Controller;

use Aleblanc\LogUi\Bridge\Symfony\Controller\UiController;
use Aleblanc\LogUi\Bridge\Symfony\Security\UiAccessGuard;
use Aleblanc\LogUi\Core\Model\LogRecord;
use Aleblanc\LogUi\Core\Model\Profile;
use Aleblanc\LogUi\Core\Model\ProfileType;
use Aleblanc\LogUi\Core\Model\QueryStats;
use Aleblanc\LogUi\Core\Storage\TelemetryFiles;
use Aleblanc\LogUi\Core\Storage\TelemetryReader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

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
            new TelemetryFiles($this->file),
            self::twig(),
            new UiAccessGuard($env, $password),
            '/_logui',
        );
    }

    private static function twig(): Environment
    {
        $loader = new FilesystemLoader();
        $loader->addPath(\dirname(__DIR__, 4).'/templates', 'LogUi');

        return new Environment($loader);
    }

    private function profile(string $id, string $label, string $level, ?string $method = 'GET'): Profile
    {
        return new Profile($id, ProfileType::Http, new \DateTimeImmutable('2026-06-10T14:00:00+00:00'), $label, 200, 1.0, 1.0, QueryStats::empty(), [$level => 1], [new LogRecord($level, 'app', 'msg', [], 0.0)], null, false, $method);
    }

    public function test_the_request_list_redirects_to_the_explorer_preset(): void
    {
        $response = $this->controller()->list();

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/_logui?types%5B%5D=request&types%5B%5D=command', $response->headers->get('Location'));
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

    public function test_detail_links_to_the_logs_of_this_request(): void
    {
        // Profile 'a' at 2026-06-10T14:00:00+00:00, 1 ms duration → window ±2 s around it.
        $html = (string) $this->controller()->detail('a', Request::create('/_logui/a'))->getContent();

        self::assertStringContainsString('/_logui?from=1781099998000&amp;to=1781100002001', $html);
        self::assertStringContainsString('grep=a', $html);
    }

    public function test_detail_404_for_unknown_id(): void
    {
        self::assertSame(404, $this->controller()->detail('zzz', Request::create('/_logui/zzz'))->getStatusCode());
    }

    public function test_denied_when_guard_refuses(): void
    {
        self::assertSame(403, $this->controller('prod', null)->detail('a', Request::create('/_logui/a'))->getStatusCode());
    }
}

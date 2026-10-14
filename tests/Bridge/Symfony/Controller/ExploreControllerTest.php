<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Tests\Bridge\Symfony\Controller;

use Aleblanc\LogUi\Bridge\Symfony\Controller\ExploreController;
use Aleblanc\LogUi\Bridge\Symfony\Log\RawLogSources;
use Aleblanc\LogUi\Bridge\Symfony\Monolog\HandlerPathDiscovery;
use Aleblanc\LogUi\Bridge\Symfony\Security\UiAccessGuard;
use Aleblanc\LogUi\Core\Model\LogRecord;
use Aleblanc\LogUi\Core\Model\Profile;
use Aleblanc\LogUi\Core\Model\ProfileType;
use Aleblanc\LogUi\Core\Model\QueryStats;
use Aleblanc\LogUi\Core\Query\LogExplorer;
use Aleblanc\LogUi\Core\Storage\MergedLogReader;
use Aleblanc\LogUi\Core\Storage\PlainLogReader;
use Aleblanc\LogUi\Core\Storage\TelemetryReader;
use Aleblanc\LogUi\Core\Time\TimestampParser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class ExploreControllerTest extends TestCase
{
    private string $file;
    private int $at;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir().'/logui-explore-'.uniqid().'.log';
        $this->at = time() - 60;
        $profile = new Profile('r1', ProfileType::Http, new \DateTimeImmutable('@'.$this->at), 'GET app_home', 200, 12.0, 3.0, QueryStats::empty(), ['info' => 1], [new LogRecord('info', 'app', 'hello there', [], 0.5)], null, false, 'GET');
        file_put_contents($this->file, implode("\n", [
            sprintf('[%s] app.INFO: plain line', gmdate('Y-m-d\TH:i:sP', $this->at)),
            TelemetryReader::SENTINEL.json_encode($profile->toArray(), \JSON_UNESCAPED_SLASHES),
        ])."\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    private function controller(): ExploreController
    {
        $loader = new FilesystemLoader();
        $loader->addPath(\dirname(__DIR__, 4).'/templates', 'LogUi');

        return new ExploreController(
            new RawLogSources(new HandlerPathDiscovery(), [], [$this->file]),
            new LogExplorer(new MergedLogReader(new PlainLogReader(new TimestampParser('UTC')))),
            new Environment($loader),
            new UiAccessGuard('dev', null),
            '/_logui',
        );
    }

    private function ref(): string
    {
        return basename($this->file);
    }

    public function test_query_lists_profile_rows_with_their_header_and_no_context(): void
    {
        $body = json_decode((string) $this->controller()->query(Request::create('/_logui/explore/query', 'GET', ['types' => ['request']]))->getContent(), true);

        self::assertSame(1, $body['total']);
        $row = $body['rows'][0];
        self::assertSame('request', $row['type']);
        self::assertSame('r1', $row['profile']['id']);
        self::assertNull($row['context'], 'the full payload is fetched on demand, not sent with the list');
    }

    public function test_query_lists_log_rows_with_type_log(): void
    {
        $body = json_decode((string) $this->controller()->query(Request::create('/_logui/explore/query', 'GET', ['types' => ['log']]))->getContent(), true);

        self::assertSame(1, $body['total']);
        self::assertSame('log', $body['rows'][0]['type']);
        self::assertNull($body['rows'][0]['profile']);
        self::assertArrayHasKey('extra', $body['rows'][0], 'a log row exposes its extra for the pivot');
    }

    public function test_profile_endpoint_returns_the_full_capture(): void
    {
        $response = $this->controller()->profile(Request::create('/_logui/explore/profile', 'GET', ['file' => $this->ref(), 'ts' => $this->at * 1000, 'id' => 'r1']));

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('GET app_home', $body['label']);
        self::assertSame('hello there', $body['records'][0]['msg']);
    }

    public function test_profile_endpoint_404s_on_unknown_id_or_file(): void
    {
        self::assertSame(404, $this->controller()->profile(Request::create('/_logui/explore/profile', 'GET', ['file' => $this->ref(), 'ts' => $this->at * 1000, 'id' => 'zzz']))->getStatusCode());
        self::assertSame(404, $this->controller()->profile(Request::create('/_logui/explore/profile', 'GET', ['file' => '../../etc/passwd', 'ts' => $this->at * 1000, 'id' => 'r1']))->getStatusCode());
    }

    public function test_query_narrows_by_pattern(): void
    {
        $body = json_decode((string) $this->controller()->query(Request::create('/_logui/explore/query', 'GET', ['pattern' => 'plain line']))->getContent(), true);

        self::assertSame(1, $body['total']);
        self::assertSame('plain line', $body['rows'][0]['message']);
    }

    public function test_histogram_caps_the_number_of_patterns(): void
    {
        $body = json_decode((string) $this->controller()->histogram(Request::create('/_logui/explore/histogram', 'GET', ['top' => 1]))->getContent(), true);

        self::assertLessThanOrEqual(1, \count($body['patterns']));
    }

    public function test_context_404s_on_unknown_file(): void
    {
        self::assertSame(404, $this->controller()->context(Request::create('/_logui/explore/context', 'GET', ['file' => 'nope.log', 'offset' => 0]))->getStatusCode());
    }

    public function test_context_returns_rows_and_a_pivot(): void
    {
        $response = $this->controller()->context(Request::create('/_logui/explore/context', 'GET', ['file' => $this->ref(), 'offset' => 0, 'n' => 500]));

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertArrayHasKey('pivot', $body);
        self::assertArrayHasKey('rows', $body);
        self::assertLessThanOrEqual(101, \count($body['rows']), 'n is clamped to 50 (50 + pivot + 50)');
    }

    public function test_page_carries_the_type_filter(): void
    {
        $html = (string) $this->controller()->index(Request::create('/_logui', 'GET', ['types' => ['request', 'command']]))->getContent();

        self::assertStringContainsString('data-types="request,command"', $html);
    }

    public function test_a_relative_span_sets_the_window(): void
    {
        $html = (string) $this->controller()->index(Request::create('/_logui', 'GET', ['span' => 60000]))->getContent();

        self::assertSame(1, preg_match('/data-from="(\d+)"/', $html, $from));
        self::assertSame(1, preg_match('/data-to="(\d+)"/', $html, $to));
        self::assertEqualsWithDelta(60000, (int) ($to[1] ?? 0) - (int) ($from[1] ?? 0), 2000);
    }
}

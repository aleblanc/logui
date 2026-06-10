<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Tests\Bridge\Symfony\Capture;

use Aleblanc\LogUi\Bridge\Symfony\Capture\CurrentProfile;
use Aleblanc\LogUi\Bridge\Symfony\Capture\RequestProfilerListener;
use Aleblanc\LogUi\Bridge\Symfony\Telemetry\TelemetryLogger;
use Aleblanc\LogUi\Core\Capture\ProfileContextFactory;
use Aleblanc\LogUi\Core\Capture\Redactor;
use Aleblanc\LogUi\Core\Model\Profile;
use Aleblanc\LogUi\Core\Stats\MemoryProbe;
use Aleblanc\LogUi\Core\Storage\TelemetryReader;
use Aleblanc\LogUi\Tests\Support\FixedClock;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class RequestProfilerListenerTest extends TestCase
{
    private string $logFile;

    protected function setUp(): void
    {
        $this->logFile = sys_get_temp_dir().'/logui-listener-'.uniqid().'.log';
    }

    protected function tearDown(): void
    {
        @unlink($this->logFile);
    }

    private function listener(CurrentProfile $current, FixedClock $clock): RequestProfilerListener
    {
        $factory = new ProfileContextFactory($clock, new Redactor([]), new MemoryProbe(), 1000, 50.0);
        $telemetry = new TelemetryLogger($this->logFile);

        return new RequestProfilerListener($current, $factory, $telemetry, ['/_logui', '/_wdt']);
    }

    /** @return list<Profile> */
    private function captured(): array
    {
        return (new TelemetryReader())->read($this->logFile);
    }

    public function test_main_request_is_profiled_and_logged_on_terminate(): void
    {
        $clock = new FixedClock(new \DateTimeImmutable('2026-06-10T14:00:00+00:00'));
        $current = new CurrentProfile();
        $listener = $this->listener($current, $clock);

        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('/checkout', 'GET');

        $listener->onKernelRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $current->addRecord('info', 'app', 'in handler', []);
        $listener->onKernelTerminate(new TerminateEvent($kernel, $request, new Response('', 201)));

        $profiles = $this->captured();
        self::assertCount(1, $profiles);
        self::assertSame('GET /checkout', $profiles[0]->label);
        self::assertSame('GET', $profiles[0]->method);
        self::assertSame(201, $profiles[0]->status);
    }

    public function test_captures_an_uncaught_exception(): void
    {
        $clock = new FixedClock(new \DateTimeImmutable('2026-06-10T14:00:00+00:00'));
        $current = new CurrentProfile();
        $listener = $this->listener($current, $clock);

        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('/boom', 'GET');
        $listener->onKernelRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $listener->onKernelException(new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new \RuntimeException('kaboom')));
        $listener->onKernelTerminate(new TerminateEvent($kernel, $request, new Response('', 500)));

        $profiles = $this->captured();
        self::assertCount(1, $profiles);
        self::assertNotNull($profiles[0]->exception);
        self::assertSame(\RuntimeException::class, $profiles[0]->exception['class']);
    }

    public function test_ui_path_is_not_profiled(): void
    {
        $clock = new FixedClock(new \DateTimeImmutable('2026-06-10T14:00:00+00:00'));
        $current = new CurrentProfile();
        $listener = $this->listener($current, $clock);

        $kernel = $this->createMock(HttpKernelInterface::class);
        $listener->onKernelRequest(new RequestEvent($kernel, Request::create('/_logui', 'GET'), HttpKernelInterface::MAIN_REQUEST));

        self::assertFalse($current->isActive());
    }

    public function test_dev_toolbar_path_is_not_profiled(): void
    {
        $clock = new FixedClock(new \DateTimeImmutable('2026-06-10T14:00:00+00:00'));
        $current = new CurrentProfile();
        $listener = $this->listener($current, $clock);

        $kernel = $this->createMock(HttpKernelInterface::class);
        $listener->onKernelRequest(new RequestEvent($kernel, Request::create('/_wdt/abc123', 'GET'), HttpKernelInterface::MAIN_REQUEST));

        self::assertFalse($current->isActive());
    }

    public function test_sub_requests_are_ignored(): void
    {
        $clock = new FixedClock(new \DateTimeImmutable('2026-06-10T14:00:00+00:00'));
        $current = new CurrentProfile();
        $listener = $this->listener($current, $clock);

        $kernel = $this->createMock(HttpKernelInterface::class);
        $listener->onKernelRequest(new RequestEvent($kernel, Request::create('/x'), HttpKernelInterface::SUB_REQUEST));

        self::assertFalse($current->isActive());
    }
}

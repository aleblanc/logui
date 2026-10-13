<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Bridge\Symfony\Capture;

use Aleblanc\LogUi\Bridge\Symfony\Telemetry\TelemetryLogger;
use Aleblanc\LogUi\Core\Capture\IdGenerator;
use Aleblanc\LogUi\Core\Capture\ProfileContextFactory;
use Aleblanc\LogUi\Core\Capture\RandomIdGenerator;
use Aleblanc\LogUi\Core\Model\ProfileType;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class RequestProfilerListener implements EventSubscriberInterface
{
    private IdGenerator $ids;

    /**
     * @param list<string> $ignorePaths request path prefixes that must NOT be profiled
     *                                  (the LogUI UI itself + dev tools like /_wdt, /_profiler)
     * @param bool         $enabled     false disables request profiling entirely — see
     *                                  {@see onKernelRequest()}
     */
    public function __construct(
        private readonly CurrentProfile $current,
        private readonly ProfileContextFactory $factory,
        private readonly TelemetryLogger $telemetry,
        private readonly array $ignorePaths,
        ?IdGenerator $ids = null,
        private readonly bool $enabled = true,
    ) {
        $this->ids = $ids ?? new RandomIdGenerator();
    }

    /** @return array<string,array{0:string,1:int}> */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 4096],
            KernelEvents::EXCEPTION => ['onKernelException', 0],
            KernelEvents::TERMINATE => ['onKernelTerminate', 0],
        ];
    }

    /**
     * One profile per HTTP request means one telemetry line per page view. That is the point on a
     * back-office; on a public site with real traffic it is a line per visitor per page, and the
     * log file grows accordingly. `capture_requests: false` turns it off and leaves the log
     * explorer — which reads your ordinary log files — working exactly as before.
     */
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$this->enabled || !$event->isMainRequest()) {
            return;
        }
        $path = $event->getRequest()->getPathInfo();
        foreach ($this->ignorePaths as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return; // LogUI UI + dev tools (_wdt/_profiler) are never profiled
            }
        }

        $method = $event->getRequest()->getMethod();
        $label = $method.' '.$path;
        $this->current->begin($this->factory->create($this->ids->generate(), ProfileType::Http, $label));
        $this->current->setMethod($method);
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $this->current->setException($event->getThrowable());
    }

    public function onKernelTerminate(TerminateEvent $event): void
    {
        if (!$this->current->isActive()) {
            return;
        }

        // Refine the label with the matched route name now that routing has run.
        $request = $event->getRequest();
        $route = $request->attributes->get('_route');
        if (\is_string($route) && '' !== $route) {
            $this->current->setLabel($request->getMethod().' '.$route);
        }

        try {
            $profile = $this->current->finish($event->getResponse()->getStatusCode());
            if (null !== $profile) {
                $this->telemetry->log($profile);
            }
        } catch (\Throwable) {
            // Observability must never break the host app: swallow storage/finish errors.
        }
    }
}

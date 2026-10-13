<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Bridge\Symfony\Security;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * On any LogUI response, lets the guard mint its session cookie after a successful password login,
 * so the user stays authenticated while navigating (see UiAccessGuard::stampAuthCookie()).
 */
final class UiAuthCookieListener implements EventSubscriberInterface
{
    /** Every route this bundle declares is named `logui_…`. */
    private const ROUTE_PREFIX = 'logui_';

    public function __construct(
        private readonly UiAccessGuard $guard,
        private readonly string $uiPath,
    ) {
    }

    /** @return array<string,string> */
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => 'onKernelResponse'];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        if (!$this->isOurs($event->getRequest())) {
            return;
        }

        $this->guard->stampAuthCookie($event->getRequest(), $event->getResponse());
    }

    /**
     * Is this response one of ours?
     *
     * Decided from the matched route name, not from the path. `ui_path` is the address a *browser*
     * uses; behind a proxy that mounts the application under a prefix the kernel sees the internal
     * path instead — `/_logui/…` where `ui_path` says `/shop/_logui`. Comparing the two silently
     * never matched, so the login cookie was never minted and every XHR the page made came back
     * 403: the UI loaded and then refused to show anything.
     *
     * The route name has no such ambiguity.
     */
    private function isOurs(Request $request): bool
    {
        $route = $request->attributes->get('_route');

        return (\is_string($route) && str_starts_with($route, self::ROUTE_PREFIX))
            || str_starts_with($request->getPathInfo(), $this->uiPath);
    }
}

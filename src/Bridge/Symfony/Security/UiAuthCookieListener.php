<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Bridge\Symfony\Security;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * On any LogUI response, lets the guard mint its session cookie after a successful password login,
 * so the user stays authenticated while navigating (see UiAccessGuard::stampAuthCookie()).
 */
final class UiAuthCookieListener implements EventSubscriberInterface
{
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

        if (!str_starts_with($event->getRequest()->getPathInfo(), $this->uiPath)) {
            return;
        }

        $this->guard->stampAuthCookie($event->getRequest(), $event->getResponse());
    }
}

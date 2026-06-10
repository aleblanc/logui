<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Tests\Bridge\Symfony\Security;

use Aleblanc\LogUi\Bridge\Symfony\Security\UiAccessGuard;
use Aleblanc\LogUi\Bridge\Symfony\Security\UiAuthCookieListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class UiAuthCookieListenerTest extends TestCase
{
    private function dispatch(Request $request): Response
    {
        $guard = new UiAccessGuard(environment: 'prod', password: 's3cret', uiPath: '/_logui');
        $listener = new UiAuthCookieListener($guard, '/_logui');
        $response = new Response();

        $kernel = $this->createMock(HttpKernelInterface::class);
        $listener->onKernelResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response));

        return $response;
    }

    public function test_mints_cookie_on_a_ui_response_after_login(): void
    {
        $response = $this->dispatch(Request::create('/_logui?_pw=s3cret'));

        self::assertCount(1, $response->headers->getCookies());
        self::assertSame(UiAccessGuard::AUTH_COOKIE, $response->headers->getCookies()[0]->getName());
    }

    public function test_ignores_responses_outside_the_ui_path(): void
    {
        $response = $this->dispatch(Request::create('/some/app/page?_pw=s3cret'));

        self::assertCount(0, $response->headers->getCookies(), 'never touches non-LogUI responses');
    }
}

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
    private function dispatch(Request $request, string $uiPath = '/_logui'): Response
    {
        $guard = new UiAccessGuard(environment: 'prod', password: 's3cret', uiPath: $uiPath);
        $listener = new UiAuthCookieListener($guard, $uiPath);
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

    /**
     * `ui_path` is the address a browser uses. Behind a proxy that mounts the application under a
     * prefix, the kernel sees the internal path instead — so matching on the path alone never
     * fired, the login cookie was never minted, and every XHR the page made came back 403: the UI
     * loaded and then refused to show anything. The matched route name is unambiguous.
     */
    public function test_mints_cookie_when_a_proxy_strips_the_mount_prefix(): void
    {
        $request = Request::create('/_logui/explore?_pw=s3cret');        // what the kernel sees
        $request->attributes->set('_route', 'logui_explore');

        $response = $this->dispatch($request, uiPath: '/shop/_logui');   // what the browser uses

        self::assertCount(1, $response->headers->getCookies(), 'the session must still be minted');
    }

    public function test_the_ajax_endpoints_mint_it_too(): void
    {
        // The page is what carries ?_pw=, but a deep link straight to an endpoint must work too.
        $request = Request::create('/_logui/explore/query?_pw=s3cret&q=');
        $request->attributes->set('_route', 'logui_explore_query');

        $response = $this->dispatch($request, uiPath: '/shop/_logui');

        self::assertCount(1, $response->headers->getCookies());
    }

    public function test_a_host_route_outside_the_bundle_is_left_alone(): void
    {
        $request = Request::create('/shop/checkout?_pw=s3cret');
        $request->attributes->set('_route', 'app_checkout');

        $response = $this->dispatch($request, uiPath: '/shop/_logui');

        self::assertCount(0, $response->headers->getCookies());
    }

    public function test_no_cookie_without_a_valid_password(): void
    {
        $request = Request::create('/_logui/explore?_pw=wrong');
        $request->attributes->set('_route', 'logui_explore');

        $response = $this->dispatch($request, uiPath: '/shop/_logui');

        self::assertCount(0, $response->headers->getCookies(), 'a failed login must not mint a session');
    }
}

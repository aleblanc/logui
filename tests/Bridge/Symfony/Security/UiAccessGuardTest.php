<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Tests\Bridge\Symfony\Security;

use Aleblanc\LogUi\Bridge\Symfony\Security\UiAccessGuard;
use Aleblanc\LogUi\Tests\Support\FixedClock;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class UiAccessGuardTest extends TestCase
{
    public function test_dev_is_always_allowed(): void
    {
        $guard = new UiAccessGuard(environment: 'dev', password: null);

        self::assertTrue($guard->authorize(Request::create('/_logui')));
    }

    public function test_test_env_is_always_allowed(): void
    {
        $guard = new UiAccessGuard(environment: 'test', password: null);

        self::assertTrue($guard->authorize(Request::create('/_logui')));
    }

    public function test_prod_without_password_is_denied(): void
    {
        $guard = new UiAccessGuard(environment: 'prod', password: null);

        self::assertFalse($guard->authorize(Request::create('/_logui')));
    }

    public function test_prod_with_correct_password_is_allowed(): void
    {
        $guard = new UiAccessGuard(environment: 'prod', password: 's3cret');

        $request = Request::create('/_logui');
        $request->headers->set('X-LogUI-Password', 's3cret');

        self::assertTrue($guard->authorize($request));
    }

    public function test_prod_with_wrong_password_is_denied(): void
    {
        $guard = new UiAccessGuard(environment: 'prod', password: 's3cret');

        $request = Request::create('/_logui?_pw=nope');

        self::assertFalse($guard->authorize($request));
    }

    public function test_delegate_mode_always_allows_even_in_prod_without_password(): void
    {
        // In delegate mode the host app's firewall is responsible (e.g. access_control ROLE_ADMIN),
        // so LogUI's own gate must not block — in any environment, with or without a password.
        $guard = new UiAccessGuard(environment: 'prod', password: null, access: 'delegate');

        self::assertTrue($guard->authorize(Request::create('/_logui')));
    }

    public function test_a_valid_password_mints_a_session_cookie_so_navigation_keeps_working(): void
    {
        $guard = new UiAccessGuard(environment: 'prod', password: 's3cret', uiPath: '/_logui');

        $request = Request::create('/_logui?_pw=s3cret');
        $response = new Response();
        $guard->stampAuthCookie($request, $response);

        $cookies = $response->headers->getCookies();
        self::assertCount(1, $cookies, 'a valid login sets one auth cookie');
        $cookie = $cookies[0];
        self::assertSame(UiAccessGuard::AUTH_COOKIE, $cookie->getName());
        self::assertNotSame('s3cret', $cookie->getValue(), 'the cookie never carries the raw password');
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame('/_logui', $cookie->getPath());

        // The next request — no ?_pw=, just the cookie — is authorized.
        $next = Request::create('/_logui/logs');
        $next->cookies->set(UiAccessGuard::AUTH_COOKIE, (string) $cookie->getValue());
        self::assertTrue($guard->authorize($next), 'the minted cookie authorizes subsequent navigation');
    }

    public function test_a_forged_cookie_is_rejected(): void
    {
        $guard = new UiAccessGuard(environment: 'prod', password: 's3cret');

        $request = Request::create('/_logui/logs');
        $request->cookies->set(UiAccessGuard::AUTH_COOKIE, 'forged-or-stale-value');

        self::assertFalse($guard->authorize($request));
    }

    public function test_no_cookie_is_minted_for_a_wrong_password(): void
    {
        $guard = new UiAccessGuard(environment: 'prod', password: 's3cret', uiPath: '/_logui');

        $response = new Response();
        $guard->stampAuthCookie(Request::create('/_logui?_pw=nope'), $response);

        self::assertCount(0, $response->headers->getCookies(), 'a failed login mints nothing');
    }

    public function test_no_cookie_is_minted_in_open_or_delegate_modes(): void
    {
        $response = new Response();
        (new UiAccessGuard(environment: 'dev', password: 's3cret'))
            ->stampAuthCookie(Request::create('/_logui?_pw=s3cret'), $response);
        (new UiAccessGuard(environment: 'prod', password: 's3cret', access: 'delegate'))
            ->stampAuthCookie(Request::create('/_logui?_pw=s3cret'), $response);

        self::assertCount(0, $response->headers->getCookies());
    }

    public function test_an_expired_cookie_is_rejected(): void
    {
        $clock = new FixedClock(new \DateTimeImmutable('2026-01-01 12:00:00'));
        $guard = new UiAccessGuard(environment: 'prod', password: 's3cret', uiPath: '/_logui', clock: $clock);

        $response = new Response();
        $guard->stampAuthCookie(Request::create('/_logui?_pw=s3cret'), $response);
        $token = (string) $response->headers->getCookies()[0]->getValue();

        $next = Request::create('/_logui/logs');
        $next->cookies->set(UiAccessGuard::AUTH_COOKIE, $token);
        self::assertTrue($guard->authorize($next), 'valid before expiry');

        $clock->advanceSeconds(9 * 3600); // past the 8h TTL
        self::assertFalse($guard->authorize($next), 'rejected once the token has expired');
    }

    public function test_a_tampered_token_is_rejected(): void
    {
        $guard = new UiAccessGuard(environment: 'prod', password: 's3cret', uiPath: '/_logui');

        $response = new Response();
        $guard->stampAuthCookie(Request::create('/_logui?_pw=s3cret'), $response);
        [$exp, $sig] = explode('.', (string) $response->headers->getCookies()[0]->getValue(), 2);

        // Try to extend the expiry while keeping the old signature → signature no longer matches.
        $forged = ((int) $exp + 999999).'.'.$sig;
        $next = Request::create('/_logui/logs');
        $next->cookies->set(UiAccessGuard::AUTH_COOKIE, $forged);

        self::assertFalse($guard->authorize($next));
    }
}

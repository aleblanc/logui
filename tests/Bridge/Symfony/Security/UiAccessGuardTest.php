<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Tests\Bridge\Symfony\Security;

use Aleblanc\LogUi\Bridge\Symfony\Security\UiAccessGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

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
}

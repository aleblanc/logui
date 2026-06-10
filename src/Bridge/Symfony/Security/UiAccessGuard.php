<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Bridge\Symfony\Security;

use Aleblanc\LogUi\Core\Stats\Clock;
use Aleblanc\LogUi\Core\Stats\SystemClock;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class UiAccessGuard
{
    /** Environments where the UI is open without a password (non-production, debug envs). */
    private const OPEN_ENVIRONMENTS = ['dev', 'test'];

    /** Session cookie that keeps the user logged in across page navigation in password mode. */
    public const AUTH_COOKIE = 'logui_auth';

    /** How long a minted login cookie stays valid (server-enforced, independent of the browser session). */
    private const TOKEN_TTL_SECONDS = 28800; // 8h

    /**
     * @param 'password'|'delegate' $access 'password' = LogUI's built-in gate (dev/test open, prod
     *                                      password); 'delegate' = trust the host app's firewall
     *                                      (e.g. access_control ROLE_ADMIN) and never block here.
     */
    public function __construct(
        private readonly string $environment,
        private readonly ?string $password,
        private readonly string $access = 'password',
        private readonly string $uiPath = '/',
        private readonly Clock $clock = new SystemClock(),
    ) {
    }

    public function authorize(Request $request): bool
    {
        if ('delegate' === $this->access) {
            return true;
        }

        if (\in_array($this->environment, self::OPEN_ENVIRONMENTS, true)) {
            return true;
        }

        // Fail-closed: no password configured in a non-dev env → never serve.
        if (null === $this->password || '' === $this->password) {
            return false;
        }

        // Either a valid login cookie (set on a previous request) or a freshly supplied password.
        return $this->cookieValid($request) || $this->credentialValid($request);
    }

    /**
     * After a valid password is supplied via header/query, mint a session cookie so the user
     * stays authenticated while navigating (internal links don't, and shouldn't, carry ?_pw=).
     * No-op in open/delegate modes, when no password is configured, when the password was wrong,
     * or when the cookie is already present.
     */
    public function stampAuthCookie(Request $request, Response $response): void
    {
        if ('delegate' === $this->access
            || \in_array($this->environment, self::OPEN_ENVIRONMENTS, true)
            || null === $this->password || '' === $this->password) {
            return;
        }

        if ($this->cookieValid($request) || !$this->credentialValid($request)) {
            return;
        }

        $response->headers->setCookie(
            Cookie::create(self::AUTH_COOKIE, $this->mintToken())
                ->withHttpOnly(true)
                ->withSecure($request->isSecure())
                ->withSameSite(Cookie::SAMESITE_LAX)
                ->withPath($this->uiPath)
        );
    }

    private function credentialValid(Request $request): bool
    {
        if (null === $this->password) {
            return false;
        }

        $supplied = $request->headers->get('X-LogUI-Password');
        if (!\is_string($supplied) || '' === $supplied) {
            $query = $request->query->get('_pw');
            $supplied = \is_string($query) ? $query : '';
        }

        return '' !== $supplied && hash_equals($this->password, $supplied);
    }

    private function cookieValid(Request $request): bool
    {
        $cookie = $request->cookies->get(self::AUTH_COOKIE);

        return \is_string($cookie) && $this->tokenValid($cookie);
    }

    /**
     * Mint a signed, expiring token: "<expiry>.<signature>". The signature covers the expiry,
     * so it can't be extended by tampering; the signing key is derived from the password, so
     * changing the password invalidates every outstanding token.
     */
    private function mintToken(): string
    {
        $exp = (string) ($this->clock->now()->getTimestamp() + self::TOKEN_TTL_SECONDS);

        return $exp.'.'.$this->sign($exp);
    }

    private function tokenValid(string $value): bool
    {
        $parts = explode('.', $value, 2);
        if (2 !== \count($parts) || !ctype_digit($parts[0])) {
            return false;
        }

        // Verify the signature first (constant-time), then the expiry.
        if (!hash_equals($this->sign($parts[0]), $parts[1])) {
            return false;
        }

        return (int) $parts[0] > $this->clock->now()->getTimestamp();
    }

    /** HMAC of the payload under a key derived from the password — the cookie never carries the password. */
    private function sign(string $payload): string
    {
        $key = hash_hmac('sha256', 'logui-auth-key', (string) $this->password);

        return hash_hmac('sha256', $payload, $key);
    }
}

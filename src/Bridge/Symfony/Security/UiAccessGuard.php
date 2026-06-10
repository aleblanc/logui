<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Bridge\Symfony\Security;

use Symfony\Component\HttpFoundation\Request;

final class UiAccessGuard
{
    /** Environments where the UI is open without a password (non-production, debug envs). */
    private const OPEN_ENVIRONMENTS = ['dev', 'test'];

    /**
     * @param 'password'|'delegate' $access 'password' = LogUI's built-in gate (dev/test open, prod
     *                                      password); 'delegate' = trust the host app's firewall
     *                                      (e.g. access_control ROLE_ADMIN) and never block here.
     */
    public function __construct(
        private readonly string $environment,
        private readonly ?string $password,
        private readonly string $access = 'password',
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

        $supplied = $request->headers->get('X-LogUI-Password');
        if (!\is_string($supplied) || '' === $supplied) {
            $query = $request->query->get('_pw');
            $supplied = \is_string($query) ? $query : '';
        }

        return hash_equals($this->password, $supplied);
    }
}

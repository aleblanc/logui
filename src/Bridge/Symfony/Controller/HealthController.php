<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Bridge\Symfony\Controller;

use Aleblanc\LogUi\Bridge\Symfony\Health\HealthProvider;
use Aleblanc\LogUi\Bridge\Symfony\Security\UiAccessGuard;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

final class HealthController
{
    public function __construct(
        private readonly HealthProvider $health,
        private readonly Environment $twig,
        private readonly UiAccessGuard $guard,
        private readonly string $uiPath,
    ) {
    }

    public function show(Request $request): Response
    {
        if (!$this->guard->authorize($request)) {
            return $this->render('error', 'Error', ['message' => 'Forbidden', 'status' => 403], 403);
        }

        return $this->render('health', 'Health', ['health' => $this->health->getData()]);
    }

    /** @param array<string,mixed> $vars */
    private function render(string $template, string $title, array $vars, int $status = 200): Response
    {
        return new Response($this->twig->render('@LogUi/logui/'.$template.'.html.twig', [
            'title' => $title,
            'ui_path' => $this->uiPath,
            ...$vars,
        ]), $status);
    }
}

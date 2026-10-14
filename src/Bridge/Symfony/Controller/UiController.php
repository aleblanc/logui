<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Bridge\Symfony\Controller;

use Aleblanc\LogUi\Bridge\Symfony\Security\UiAccessGuard;
use Aleblanc\LogUi\Core\Storage\TelemetryFiles;
use Aleblanc\LogUi\Core\Storage\TelemetryReader;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

final class UiController
{
    public function __construct(
        private readonly TelemetryReader $reader,
        private readonly TelemetryFiles $files,
        private readonly Environment $twig,
        private readonly UiAccessGuard $guard,
        private readonly string $uiPath,
    ) {
    }

    /**
     * The request list used to be a page of its own. It is now a preset of the explorer — same
     * data, plus the time window, the histogram, the live tail and a shareable URL. Old links
     * and bookmarks land on the preset.
     */
    public function list(): RedirectResponse
    {
        return new RedirectResponse($this->uiPath.'?types%5B%5D=request&types%5B%5D=command');
    }

    public function detail(string $id, Request $request): Response
    {
        if (!$this->guard->authorize($request)) {
            return $this->error('Forbidden', 403);
        }

        foreach ($this->reader->readAll($this->files->all()) as $profile) {
            if ($profile->id === $id) {
                return $this->render('detail', $profile->label, ['profile' => $profile]);
            }
        }

        return $this->error('Profile not found', 404);
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

    private function error(string $message, int $status): Response
    {
        return $this->render('error', 'Error', ['message' => $message, 'status' => $status], $status);
    }
}

<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Bridge\Symfony\Controller;

use Aleblanc\LogUi\Bridge\Symfony\Security\UiAccessGuard;
use Aleblanc\LogUi\Core\Model\ProfileType;
use Aleblanc\LogUi\Core\Query\ProfileFilter;
use Aleblanc\LogUi\Core\Query\ProfileSorter;
use Aleblanc\LogUi\Core\Query\ProfileSummary;
use Aleblanc\LogUi\Core\Storage\TelemetryReader;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

final class UiController
{
    public function __construct(
        private readonly TelemetryReader $reader,
        private readonly string $telemetryFile,
        private readonly Environment $twig,
        private readonly UiAccessGuard $guard,
        private readonly string $uiPath,
    ) {
    }

    public function list(Request $request): Response
    {
        if (!$this->guard->authorize($request)) {
            return $this->error('Forbidden', 403);
        }

        $level = $this->str($request, 'level');
        $type = $this->str($request, 'type');
        $q = $this->str($request, 'q');
        $method = $this->str($request, 'method');
        $filter = new ProfileFilter(
            level: '' !== $level ? $level : null,
            type: 'http' === $type ? ProfileType::Http : ('cli' === $type ? ProfileType::Cli : null),
            search: '' !== $q ? $q : null,
            method: '' !== $method ? $method : null,
        );

        $all = $this->reader->read($this->telemetryFile);

        // Stats header is the GENERAL count over everything — not recomputed after filtering.
        $summary = ProfileSummary::of($all);

        $matched = array_filter($all, static fn ($profile): bool => $filter->matches($profile));
        $matched = (new ProfileSorter())->sort(array_values($matched), field: 'at', direction: 'desc');

        $perPage = 100;
        $total = \count($matched);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($pages, (int) $this->str($request, 'page')));
        $pageProfiles = \array_slice($matched, ($page - 1) * $perPage, $perPage);

        return $this->render('list', 'Requests', [
            'profiles' => $pageProfiles,
            'summary' => $summary,
            'filter' => ['level' => $level, 'type' => $type, 'q' => $q, 'method' => $method],
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
        ]);
    }

    public function detail(string $id, Request $request): Response
    {
        if (!$this->guard->authorize($request)) {
            return $this->error('Forbidden', 403);
        }

        foreach ($this->reader->read($this->telemetryFile) as $profile) {
            if ($profile->id === $id) {
                return $this->render('detail', $profile->label, ['profile' => $profile]);
            }
        }

        return $this->error('Profile not found', 404);
    }

    private function str(Request $request, string $key): string
    {
        $value = $request->query->get($key);

        return \is_string($value) ? $value : '';
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

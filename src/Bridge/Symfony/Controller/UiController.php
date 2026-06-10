<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Bridge\Symfony\Controller;

use Aleblanc\LogUi\Bridge\Symfony\Security\UiAccessGuard;
use Aleblanc\LogUi\Core\Model\ProfileType;
use Aleblanc\LogUi\Core\Query\ProfileFilter;
use Aleblanc\LogUi\Core\Query\ProfileSorter;
use Aleblanc\LogUi\Core\Query\ProfileSummary;
use Aleblanc\LogUi\Core\Storage\TelemetryReader;
use Aleblanc\LogUi\Core\Ui\Renderer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class UiController
{
    public function __construct(
        private readonly TelemetryReader $reader,
        private readonly string $telemetryFile,
        private readonly Renderer $renderer,
        private readonly UiAccessGuard $guard,
        private readonly string $uiPath,
    ) {
    }

    public function list(Request $request): Response
    {
        if (!$this->guard->authorize($request)) {
            return new Response('LogUI: forbidden', 403);
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

        $body = $this->renderer->render('list', [
            'profiles' => $pageProfiles,
            'summary' => $summary,
            'filter' => ['level' => $level, 'type' => $type, 'q' => $q, 'method' => $method],
            'uiPath' => $this->uiPath,
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
        ]);

        return $this->page('Requests', $body);
    }

    public function detail(string $id, Request $request): Response
    {
        if (!$this->guard->authorize($request)) {
            return new Response('LogUI: forbidden', 403);
        }

        foreach ($this->reader->read($this->telemetryFile) as $profile) {
            if ($profile->id === $id) {
                return $this->page($profile->label, $this->renderer->render('detail', [
                    'profile' => $profile,
                    'uiPath' => $this->uiPath,
                ]));
            }
        }

        return new Response('LogUI: profile not found', 404);
    }

    private function str(Request $request, string $key): string
    {
        $value = $request->query->get($key);

        return \is_string($value) ? $value : '';
    }

    private function page(string $title, string $content): Response
    {
        return new Response($this->renderer->render('layout', ['title' => $title, 'content' => $content, 'uiPath' => $this->uiPath]));
    }
}

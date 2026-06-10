<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Bridge\Symfony\Controller;

use Aleblanc\LogUi\Bridge\Symfony\Log\RawLogSources;
use Aleblanc\LogUi\Bridge\Symfony\Security\UiAccessGuard;
use Aleblanc\LogUi\Core\Storage\PlainLogReader;
use Aleblanc\LogUi\Core\Ui\Renderer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class RawLogController
{
    public function __construct(
        private readonly RawLogSources $sources,
        private readonly PlainLogReader $reader,
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

        return $this->page('Log files', $this->renderer->render('logs', [
            'sources' => $this->sources->all(),
            'uiPath' => $this->uiPath,
        ]));
    }

    public function view(Request $request): Response
    {
        if (!$this->guard->authorize($request)) {
            return new Response('LogUI: forbidden', 403);
        }

        $path = (string) $request->query->get('path', '');
        if (!$this->sources->knows($path)) {
            return new Response('LogUI: unknown log source', 403);
        }

        // Only the tail of the file is read (bounded memory) — safe even on multi-GB logs.
        $all = [];
        foreach ($this->reader->readTail($path) as $row) {
            $all[] = $row;
        }
        $all = array_reverse($all); // newest first

        // Distinct levels/channels present in the window → filter dropdowns.
        $levels = $channels = [];
        foreach ($all as $r) {
            if ('' !== $r['level']) {
                $levels[$r['level']] = true;
            }
            if ('' !== $r['channel']) {
                $channels[$r['channel']] = true;
            }
        }
        $levels = array_keys($levels);
        $channels = array_keys($channels);
        sort($levels);
        sort($channels);

        $level = $this->str($request, 'level');
        $channel = $this->str($request, 'channel');
        $q = $this->str($request, 'q');

        $matched = array_values(array_filter($all, static function (array $r) use ($level, $channel, $q): bool {
            if ('' !== $level && 0 !== strcasecmp($r['level'], $level)) {
                return false;
            }
            if ('' !== $channel && $r['channel'] !== $channel) {
                return false;
            }
            if ('' !== $q && false === mb_stripos($r['message'].' '.($r['context'] ?? ''), $q)) {
                return false;
            }

            return true;
        }));

        $perPage = 100;
        $total = \count($matched);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($pages, (int) $this->str($request, 'page')));
        $rows = \array_slice($matched, ($page - 1) * $perPage, $perPage);

        return $this->page(basename($path), $this->renderer->render('log_view', [
            'path' => $path,
            'rows' => $rows,
            'levels' => $levels,
            'channels' => $channels,
            'filter' => ['level' => $level, 'channel' => $channel, 'q' => $q],
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
            'uiPath' => $this->uiPath,
        ]));
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

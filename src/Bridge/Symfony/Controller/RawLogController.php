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
        $rows = [];
        foreach ($this->reader->readTail($path) as $row) {
            $rows[] = $row;
        }
        $rows = \array_slice(array_reverse($rows), 0, 500); // newest first, capped

        return $this->page(basename($path), $this->renderer->render('log_view', [
            'path' => $path,
            'rows' => $rows,
            'uiPath' => $this->uiPath,
        ]));
    }

    private function page(string $title, string $content): Response
    {
        return new Response($this->renderer->render('layout', ['title' => $title, 'content' => $content, 'uiPath' => $this->uiPath]));
    }
}

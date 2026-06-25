<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Bridge\Symfony\Controller;

use Aleblanc\LogUi\Bridge\Symfony\Log\RawLogSources;
use Aleblanc\LogUi\Bridge\Symfony\Security\UiAccessGuard;
use Aleblanc\LogUi\Core\Storage\PlainLogReader;
use Aleblanc\LogUi\Core\Storage\TelemetryReader;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

final class RawLogController
{
    public function __construct(
        private readonly RawLogSources $sources,
        private readonly PlainLogReader $reader,
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

        return $this->render('logs', 'Log files', ['sources' => $this->sources->all()]);
    }

    public function view(Request $request): Response
    {
        if (!$this->guard->authorize($request)) {
            return $this->error('Forbidden', 403);
        }

        $ref = $this->str($request, 'ref');
        $path = $this->sources->resolve($ref);
        if (null === $path) {
            return $this->error('Unknown log source', 403);
        }

        // Only the tail of the file is read (bounded memory) — safe even on multi-GB logs.
        $all = [];
        foreach ($this->reader->readTail($path) as $row) {
            // Skip LogUI's own telemetry lines (LOGUI@{json}) — they belong to the Requests
            // tab, not the raw viewer, and their big JSON payload would otherwise pollute it.
            if (str_contains($row['message'], TelemetryReader::SENTINEL)) {
                continue;
            }
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

        return $this->render('log_view', basename($path), [
            'ref' => $ref,
            'name' => basename($path),
            'rows' => $rows,
            'levels' => $levels,
            'channels' => $channels,
            'filter' => ['level' => $level, 'channel' => $channel, 'q' => $q],
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
        ]);
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

<?php

declare(strict_types=1);

namespace Aleblanc\LogUi\Bridge\Symfony\Controller;

use Aleblanc\LogUi\Bridge\Symfony\Log\RawLogSources;
use Aleblanc\LogUi\Bridge\Symfony\Security\UiAccessGuard;
use Aleblanc\LogUi\Core\Query\Grep;
use Aleblanc\LogUi\Core\Query\LogExplorer;
use Aleblanc\LogUi\Core\Query\LogQL\Evaluator as LogQlEvaluator;
use Aleblanc\LogUi\Core\Query\LogQL\SyntaxError;
use Aleblanc\LogUi\Core\Query\Selection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Twig\Environment;

/**
 * The log explorer: a LogQL-lite search box, a zoomable histogram and a live tail, over one file
 * or all of them at once.
 *
 * The page itself is rendered by Twig like the rest of LogUI; the histogram and the result table
 * then talk to the JSON endpoints below, which is what makes zooming and live tailing possible
 * without a front-end build step.
 */
final class ExploreController
{
    /** Default window when none is given: the last hour. */
    private const DEFAULT_SPAN_MS = 3_600_000;

    /**
     * Rows returned when the caller does not say.
     *
     * Matches the explorer's own default so the API and the UI agree; the page sends the value
     * picked in its row-count select, up to {@see LogExplorer::MAX_ROWS}.
     */
    private const DEFAULT_LIMIT = 100;

    /** A live stream is closed after this long; the browser reconnects on its own. */
    private const STREAM_MAX_SECONDS = 300;

    /** Delay between two polls of the files being tailed. */
    private const STREAM_POLL_US = 700_000;

    /** @param array<string,string> $views named explorer views: name => query string */
    public function __construct(
        private readonly RawLogSources $sources,
        private readonly LogExplorer $explorer,
        private readonly Environment $twig,
        private readonly UiAccessGuard $guard,
        private readonly string $uiPath,
        private readonly array $views = [],
    ) {
    }

    /** The explorer page (HTML shell; data is fetched from the JSON endpoints). */
    public function index(Request $request): Response
    {
        if (!$this->guard->authorize($request)) {
            return $this->error('Forbidden', 403);
        }

        [$from, $to] = $this->window($request);

        return new Response($this->twig->render('@LogUi/logui/explore.html.twig', [
            'title' => 'Explore',
            'ui_path' => $this->uiPath,
            'sources' => $this->sources->all(),
            'query' => $request->query->getString('q'),
            'from' => $from,
            'to' => $to,
            'selected' => $this->selectedRefs($request),
            'levels' => $this->strings($request, 'levels'),
            'channels' => $this->strings($request, 'channels'),
            'types' => $this->strings($request, 'types'),
            'pattern' => $request->query->getString('pattern'),
            'grep' => $request->query->getString('grep'),
            'grep_regex' => $request->query->getBoolean('grep_regex'),
            // No explicit end means "up to now", and the page keeps it that way: every reload
            // ends the window at the server's clock rather than at whenever the page was opened.
            'follow' => !$request->query->has('to'),
            'include_self' => $request->query->getBoolean('include_self'),
            'views' => $this->views,
        ]));
    }

    /** GET {ui}/explore/query — matching rows for the current query and window. */
    public function query(Request $request): JsonResponse
    {
        if (!$this->guard->authorize($request)) {
            return new JsonResponse(['error' => 'Forbidden'], 403);
        }

        [$from, $to] = $this->window($request);

        try {
            $result = $this->explorer->query(
                $this->resolveSources($request),
                $request->query->getString('q'),
                $from,
                $to,
                max(1, min(LogExplorer::MAX_ROWS, $request->query->getInt('limit', self::DEFAULT_LIMIT))),
                max(0, $request->query->getInt('offset')),
                $this->selection($request),
            );
        } catch (SyntaxError $e) {
            return $this->syntaxError($e);
        }

        return new JsonResponse([
            'rows' => array_map($this->publicRow(...), $result['rows']),
            'total' => $result['total'],
            'truncated' => $result['truncated'],
            'tookMs' => $result['tookMs'],
            'from' => $from,
            'to' => $to,
        ]);
    }

    /** GET {ui}/explore/histogram — record counts per time bucket, broken down by level. */
    public function histogram(Request $request): JsonResponse
    {
        if (!$this->guard->authorize($request)) {
            return new JsonResponse(['error' => 'Forbidden'], 403);
        }

        [$from, $to] = $this->window($request);

        try {
            $result = $this->explorer->histogram(
                $this->resolveSources($request),
                $request->query->getString('q'),
                $from,
                $to,
                $this->selection($request),
                max(1, min(500, $request->query->getInt('top', 20))),
            );
        } catch (SyntaxError $e) {
            return $this->syntaxError($e);
        }

        return new JsonResponse($result);
    }

    /** GET {ui}/explore/profile — the full capture (records included) behind one profile row. */
    public function profile(Request $request): JsonResponse
    {
        if (!$this->guard->authorize($request)) {
            return new JsonResponse(['error' => 'Forbidden'], 403);
        }

        $ref = $request->query->getString('file');
        $path = $this->resolveSources($request)[$ref] ?? null;
        $id = $request->query->getString('id');

        if (null === $path || '' === $id || !preg_match('/^[A-Za-z0-9]+$/', $id)) {
            return new JsonResponse(['error' => 'Profile not found'], 404);
        }

        $profile = $this->explorer->profile($ref, $path, $request->query->getInt('ts'), $id);

        return null === $profile
            ? new JsonResponse(['error' => 'Profile not found'], 404)
            : new JsonResponse($profile);
    }

    /** GET {ui}/explore/context — the lines around one record, located by its byte offset. */
    public function context(Request $request): JsonResponse
    {
        if (!$this->guard->authorize($request)) {
            return new JsonResponse(['error' => 'Forbidden'], 403);
        }

        $ref = $request->query->getString('file');
        $path = $this->resolveSources($request)[$ref] ?? null;
        if (null === $path) {
            return new JsonResponse(['error' => 'Not found'], 404);
        }

        $n = max(1, min(50, $request->query->getInt('n', 5)));
        $result = $this->explorer->context($ref, $path, max(0, $request->query->getInt('offset')), $n);

        return new JsonResponse([
            'rows' => array_map($this->publicRow(...), $result['rows']),
            'pivot' => $result['pivot'],
        ]);
    }

    /**
     * GET {ui}/explore/stream — live tail as Server-Sent Events.
     *
     * Follows the end of each selected file and pushes new records that match the query. Only the
     * bytes appended since the last poll are read, so tailing costs nothing on a huge file.
     *
     * Rotation-safe: if a file shrinks (logrotate truncated or replaced it) the offset is reset to
     * the beginning of the new file instead of blindly seeking past its end.
     */
    public function stream(Request $request): Response
    {
        if (!$this->guard->authorize($request)) {
            return new JsonResponse(['error' => 'Forbidden'], 403);
        }

        $logql = $request->query->getString('q');

        try {
            $evaluator = $this->explorer->evaluatorFor($logql);
            $selection = $this->selection($request);
        } catch (SyntaxError $e) {
            return $this->syntaxError($e);
        }

        $sources = $this->resolveSources($request);
        $deadline = microtime(true) + self::STREAM_MAX_SECONDS;

        // Start at the current end of each file: a live tail shows what happens from now on.
        $offsets = [];
        foreach ($sources as $ref => $path) {
            $offsets[$ref] = is_file($path) ? (int) filesize($path) : 0;
        }

        $response = new StreamedResponse(function () use ($sources, $offsets, $evaluator, $selection, $deadline): void {
            $this->emit('hello', ['at' => (int) (microtime(true) * 1000)]);

            while (microtime(true) < $deadline) {
                if (connection_aborted()) {
                    return;
                }

                foreach ($sources as $ref => $path) {
                    $offsets[$ref] = $this->drain($ref, $path, $offsets[$ref], $evaluator, $selection);
                }

                // A comment line doubles as a keep-alive through proxies.
                echo ": keep-alive\n\n";
                $this->flush();

                usleep(self::STREAM_POLL_US);
            }

            $this->emit('bye', ['reason' => 'timeout']);
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('X-Accel-Buffering', 'no'); // tell nginx not to buffer us

        return $response;
    }

    /**
     * Reads what was appended to a file since $offset, emits the matching rows, and returns the
     * new offset.
     */
    private function drain(string $ref, string $path, int $offset, LogQlEvaluator $evaluator, Selection $selection): int
    {
        clearstatcache(true, $path);
        if (!is_file($path)) {
            return $offset;
        }

        $size = (int) filesize($path);
        if ($size === $offset) {
            return $offset;
        }
        if ($size < $offset) {
            // The file shrank: it was rotated or truncated, so follow the new one from its start.
            $offset = 0;
        }

        foreach ($this->explorer->tailFrom($ref, $path, $offset, $evaluator, $selection) as $row) {
            $this->emit('row', $this->publicRow($row));
        }

        return $size;
    }

    /** @param array<string,mixed> $payload */
    private function emit(string $event, array $payload): void
    {
        echo 'event: '.$event."\n";
        echo 'data: '.json_encode($payload, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)."\n\n";
        $this->flush();
    }

    private function flush(): void
    {
        if (0 !== ob_get_level()) {
            @ob_flush();
        }
        flush();
    }

    /**
     * Rows as the UI consumes them — the absolute server path never leaves the server, only the
     * public ref does.
     *
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    private function publicRow(array $row): array
    {
        $profile = $row['profile'];

        return [
            'ts' => $row['ts'],
            'tsInferred' => $row['ts_inferred'],
            'date' => $row['date'],
            'file' => $row['file'],
            'channel' => $row['channel'],
            'level' => $row['level'],
            'message' => $row['message'],
            // A profile row's context is the whole capture — kilobytes per request. The list
            // only needs the header; the capture is fetched when the row is expanded.
            'context' => null === $profile ? $row['context'] : null,
            // `extra` carries the `logui_id` that pivots a log line to its request; a profile row
            // has no extra of its own.
            'extra' => null === $profile ? $row['extra'] : null,
            'type' => $row['type'],
            'profile' => $profile,
            // Byte offset of the record in its file — the anchor for fetching context lines.
            'offset' => $row['offset'],
        ];
    }

    /**
     * Files to read: the explicitly selected refs, or every known source ("all logs").
     *
     * Refs are always resolved against the allow-list, so an unknown or crafted ref simply
     * selects nothing — a path is never rebuilt from user input.
     *
     * @return array<string,string> ref => absolute path
     */
    private function resolveSources(Request $request): array
    {
        $selected = $this->selectedRefs($request);
        $resolved = [];

        foreach ($this->sources->all() as $entry) {
            if ([] === $selected || \in_array($entry['ref'], $selected, true)) {
                $resolved[$entry['ref']] = $entry['path'];
            }
        }

        return $resolved;
    }

    /** @return list<string> */
    private function selectedRefs(Request $request): array
    {
        return $this->strings($request, 'files');
    }

    /**
     * The search box and the level/channel checkboxes, ANDed with whatever the LogQL box says.
     *
     * @throws SyntaxError when the search is marked as a regex and does not compile
     */
    private function selection(Request $request): Selection
    {
        return new Selection(
            $this->strings($request, 'levels'),
            $this->strings($request, 'channels'),
            Grep::of($request->query->getString('grep'), $request->query->getBoolean('grep_regex')),
            // Hidden by default: polling the live view logs the polling, and on a quiet
            // application that noise is most of what you see. Opt back in with `include_self=1`
            // — nothing is dropped silently, the UI shows the toggle and its state.
            $request->query->getBoolean('include_self') ? [] : $this->ownPaths(),
            $this->strings($request, 'types'),
            '' === ($pattern = $request->query->getString('pattern')) ? null : $pattern,
        );
    }

    /**
     * The paths under which LogUI's own pages may appear in a log line.
     *
     * `ui_path` is the address a *browser* uses. Behind a proxy that mounts the application under
     * a prefix — `/shop/_logui` outside, `/_logui` inside — the access log records the internal
     * path, so matching the configured value alone would miss every line. Both are matched.
     *
     * @return list<string>
     */
    private function ownPaths(): array
    {
        $path = rtrim($this->uiPath, '/');
        $paths = [$path];

        $mounted = strrchr($path, '/');
        if (false !== $mounted && $mounted !== $path) {
            $paths[] = $mounted;
        }

        return $paths;
    }

    /**
     * A repeated query parameter, keeping only the scalar strings.
     *
     * `?levels[]=error&levels[][]=x` is a perfectly legal request: without this, a nested array
     * would reach code that expects a string. Values are never used to build anything — they are
     * compared against what was actually read from the logs — so filtering is enough.
     *
     * @return list<string>
     */
    private function strings(Request $request, string $key): array
    {
        return array_values(array_filter($request->query->all($key), \is_string(...)));
    }

    /**
     * The requested window, defaulting to the last hour.
     *
     * @return array{int,int}
     */
    private function window(Request $request): array
    {
        $to = $request->query->getInt('to') ?: (int) (microtime(true) * 1000);
        $span = $request->query->getInt('span');
        $from = $request->query->getInt('from');
        if (0 === $from) {
            // A relative `span` lets a saved view say "the last 24 h" without pinning a timestamp.
            $from = $span > 0 ? $to - $span : $to - self::DEFAULT_SPAN_MS;
        }

        return $from <= $to ? [$from, $to] : [$to, $from];
    }

    private function syntaxError(SyntaxError $error): JsonResponse
    {
        return new JsonResponse([
            'error' => $error->getMessage(),
            'position' => $error->position,
        ], 400);
    }

    private function error(string $message, int $status): Response
    {
        return new Response($this->twig->render('@LogUi/logui/error.html.twig', [
            'title' => 'Error',
            'ui_path' => $this->uiPath,
            'message' => $message,
            'status' => $status,
        ]), $status);
    }
}

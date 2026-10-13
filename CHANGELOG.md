# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres
to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

### Added
- **Log explorer** (`Explore` tab) with a LogQL-lite query language, a zoomable time histogram
  and a live tail, across one or all log files.
- `Core\Time\TimestampParser`: every parsed line now carries an epoch timestamp (`ts`), derived
  **at read time** — log files are never rewritten and keep their original size.
- `Core\Storage\LogSeeker` + `PlainLogReader::readRange()`: time ranges are located by binary
  search on byte offsets, so zooming into a multi-GB file stays instant without any index.
- `Core\Storage\MergedLogReader`: chronological k-way merge of several files ("all logs"),
  each record tagged with its source via the `file` label.
- JSON endpoints `GET {ui}/explore/query`, `/explore/histogram` and `/explore/stream` (SSE).
- **Plain search box** (`Core\Query\Grep`, `grep` / `grep_regex` parameters): case-insensitive,
  searches message *and* context, and treats regex metacharacters as literal text so `user(42)` is
  a search term rather than a syntax error. An opt-in `regexp` checkbox switches it to a pattern
  search, validated once at parse time like every other regex.
- **Grep what you highlighted**: selecting text inside the log table offers a one-click search for
  it, as a literal.
- **Facet filters** in the explorer: a collapsible group per field (`level`, `channel`, `file`),
  each listing the values that occur with their counts and share, built from the current result set
  (`facets` in the histogram payload). `Core\Query\Selection` ANDs them with the query rather than
  rewriting the query text. Facet counts are tallied *before* the ticked values are applied, so
  ticking `error` does not erase every other box — but *after* the search, which is part of the
  question being asked.
- **Expandable records**: each line is one dense row; clicking it unfolds every field with the
  context pretty-printed and syntax-highlighted. `expand all` and `wrap` toggles. Expansion now
  survives a re-render — records are keyed by timestamp + file + text.
- **Row-count select** (50 / 100 / 200 / 500 / 1000, default 100) in the Logs header, sent as
  `limit`. The API default moves from 200 to 100 to match.
- **Live starts from an empty list** so the call you are about to make is the only thing on
  screen, and a **clear** button empties it at any time. Leaving Live reloads the window, so the
  history is one click away. Changing the query or a filter while live restarts the capture.
  **Live** moved into the Logs panel header, next to **clear** and a new **refresh**.
- **The window has no fixed end by default.** Requests send only `from`; the server closes the
  window at its own clock, so every reload — and the refresh button — picks up whatever has been
  logged since, rather than freezing on the moment the page was opened. Zooming or panning pins
  both ends; picking a range or resetting the zoom returns to following.
- The explorer's query, time window and filters are mirrored into the URL, so a view is shareable.
- **LogUI's own HTTP traffic is hidden** from the explorer by default. Watching a live tail means
  polling, and the polling is itself logged — measured at **42 % of the records** on a quiet
  application. LogUI cannot stop those lines being written (nginx's access log and the host's
  Monolog are not its to configure), so it drops them at read time, before the facets are counted.
  A toggle in the Fields panel brings them back (`include_self=1`): a filter you cannot see is a
  trap. The internal path is matched as well as the configured `ui_path`, for installations behind
  a proxy that mounts the application under a prefix.
- **14-day and 28-day ranges** in the time select.
- **A partial result now says so.** Wide windows can outrun the query budget; the chart shows a
  warning naming the elapsed time and what to narrow, instead of quietly showing a shorter window.
- **`query_budget_seconds`** makes that budget configurable (default 5.0). It is a backstop against
  one user tying up a worker, so it stays low by default — but a slow disk sweeping weeks across
  many files legitimately needs more.

### Changed
- **The log explorer is now the landing page.** `{ui}` renders the explorer; the captured-request
  list moved to `{ui}/requests`. `{ui}/explore` still works, so existing links and bookmarks do not
  break. **Upgrading:** any link you built by hand to the request list needs `/requests` appended.
- **The search box replaces LogQL as the default input.** LogQL still does everything it did, but
  it now lives behind a *LogQL query* disclosure under the search box, and is unfolded
  automatically when a query arrives in the URL.
- **Sepia theme reworked**: copper gradients poured into the corners of the page, a matching sheen
  and highlight edge on panels and cards, and a warmer, deeper palette. Each theme now owns its
  whole backdrop via `--aurora`, plus `--sheen` and `--edge` for surfaces, so adding a theme no
  longer means editing the shared rules. Dark and light are unchanged.
- Visual overhaul of the explorer: KPI cards, per-level gradient bars with a time axis and grid,
  a richer hover card, a fields sidebar, and a live tail where arriving records slide in tinted by
  their level while the page pulses in that colour. All of it honours `prefers-reduced-motion`.
- Log rows are set in a small monospace face (`--mono`, system coding fonts — nothing is
  downloaded) at 11.5 px; the rest of the UI keeps the sans-serif.

### Fixed
- **The explorer was unusable on a production log file.** LogUI's own telemetry lines
  (`LOGUI@{…}`) carried no parseable date, and in production the telemetry file *is* the
  application's log — often almost entirely telemetry. An undated line is invisible to the binary
  search that locates a time range, and it never satisfies the "past the end of the window" test
  that stops the scan, so both degraded to reading the file whole: a gigabyte read per request,
  twice per page load. The date is now recovered from the payload's `at` field. On a 53 MB
  telemetry file, a one-day window went from reading 100 % of it to **2.1 %** (one hour: 0.2 %).
  The lines are still hidden from the explorer and the Files tab, exactly as before.
- The live tail's polling fallback (used when a proxy blocks SSE) reloaded the whole table every
  two seconds, which **folded shut any record the user had just expanded** and moved the time
  window under the chart. It now appends only what was written since the last round, de-duplicated
  against what is already on screen.

### Security
- **Fixed: in `password` mode behind a proxy that mounts the app under a prefix, the login cookie
  was never minted** — `UiAuthCookieListener` compared the kernel's internal path against the
  browser-facing `ui_path`, which never matched. The page loaded and then every XHR it made came
  back 403, so the explorer showed nothing. The listener now recognises its own responses by the
  matched route name (`logui_*`).
- User-supplied values rendered into HTML attributes are escaped explicitly with `e('html_attr')`
  rather than relying on the host application's autoescape configuration.
- User-supplied regexes are compiled and validated **once, at parse time**
  (`Core\Query\LogQL\Regex`). Previously an invalid pattern emitted a PHP warning **per line** —
  which, in a log viewer, meant writing into the very file being read.
- `preg_match()` returns `false` both for "no match" and for an error, so a pattern that blew the
  backtrack limit made the negated filters `!~` match **everything**. Matching is now tri-state and
  both `|~` and `!~` fail closed: an engine failure never selects a record.
- Queries run under a wall-clock budget (`LogExplorer::DEFAULT_BUDGET_SECONDS`, 5 s), checked every
  256 rows, which bounds the residual cost of a catastrophic pattern and of very wide windows.
  Results cut short come back with `truncated: true`.

### Notes
- Console logs (`HH:MM:SS`, no date) are placed best-effort from the file's modification time and
  flagged as inferred in the UI.
- LogQL metric queries (`rate()`, `sum by`) are intentionally out of scope: they would require an
  index, which LogUI deliberately does not keep.


## [Unreleased]

### Changed
- **Telemetry now lives in your existing logs, not a separate store.** Each profiled request/command
  is written as one `LOGUI@{json}` line into the host's log file — carrying RAM start→end→peak,
  duration, SQL count, levels, status, method **and the request's own captured log records** (all
  channels, bounded by `max_records_per_profile`). The Requests UI reads those lines back; the detail
  view shows the full per-request log records straight from the line — self-contained, no separate
  store, no dependency on which channels your file handlers persist. The `var/logui` JSON-lines store,
  `JsonLinesWriter/Reader` and `Retention` are removed (your own log rotation governs history).
  Config: `telemetry_file` replaces `storage_dir`/`retention_days`/`max_total_mb`. Stats, method column,
  filters, pagination and themes are unchanged.

### Added
- **Core library** (framework-agnostic): the profile model (`LogLevel`, `LogRecord`,
  `ProfileType`, `QueryStats`, `Profile`), runtime capture (`ProfileContext`/`Factory`,
  `RecordBuffer`, `Redactor`, id generation), pragmatic stats (`Clock`/`SystemClock`,
  `MemoryProbe`, `QueryCounter`), file storage (daily JSON-lines writer/reader with
  retention, raw Monolog `.log` reader), querying (`ProfileFilter`, `ProfileSorter`),
  and a dependency-free template `Renderer`.
- **Symfony bridge (2a)**: `LogUiBundle` auto-captures HTTP requests (Monolog records, duration,
  peak memory, uncaught exceptions) into daily JSON-lines profiles, and serves an embedded web UI
  at `/_logui` with day/level/type/search filtering. UI is open in `dev`/`test`, fail-closed
  (password) in production.
- **Symfony bridge (2b)**: console-command capture; **automatic SQL query counting + slow-query
  detection** via an opt-in-free Doctrine DBAL middleware (active when `doctrine/dbal` is present);
  **Monolog log-file auto-discovery** plus a configurable `external_logs` list, surfaced as a
  read-only **Files** tab in the UI (`config keys: discover_monolog`, `external_logs`). The raw-log
  viewer reads only the **tail** (~2 MB) of a file, so multi-GB logs open without exhausting memory.
  The Files tab also **scans the log directories** (`log_dirs`, default `%kernel.logs_dir%`) so it
  surfaces web-server logs, other channels and rotated files — not only the active Monolog handler.
  The raw-log parser is **multi-format**: Monolog, nginx access (combined), nginx/PHP error, and a
  verbatim fallback so no line is ever dropped; levels are normalised (e.g. HTTP 5xx → error).
- HTTP requests now record their **method** (GET/POST/PATCH/…) as a dedicated, filterable column;
  the dashboard stats are **clickable** (each applies its filter), and the request list paginates 100/page.
- Profile storage is bounded by **time** (`retention_days`, default 14) **and total disk size**
  (`max_total_mb`, default 200; oldest days pruned first, newest always kept).
- Slimmed the capture wiring: merged the exception listener into the request listener and dropped the
  `kernel.response` hook (route label now resolved at `kernel.terminate`) — fewer event subscriptions.

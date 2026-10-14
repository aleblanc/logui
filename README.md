# 🔎 LogUI

> **Log viewer & lightweight profiler for Symfony.** It plugs into your existing logs — no separate store, no database, no build step. MIT licensed.

LogUI is an embeddable Composer package (in the spirit of Laravel Telescope / the Symfony Profiler)
that adds a small **web UI** to your app to browse requests, console commands and raw log files.

For every HTTP request and console command it captures pragmatic **stats** (duration, RAM
start→end→peak, SQL query count, log-level counts) plus the request's own log records, and appends it
all as **one `LOGUI@{json}` line to a telemetry log file**. The UI reads those lines back. It also
reads your **raw `.log` files** directly (Monolog, nginx access/error, …).

**No separate store. No new database. No front-end build.**

![LogUI — log explorer](screenshots/explore.png)
*The landing page: search box with an opt-in `regexp` mode, zoomable histogram stacked by level with
a p95 latency overlay, KPI cards, a fields panel (type / level / channel / file / patterns) built
from what the query actually matched, and a live tail. Records are one dense monospace line; click
one to unfold every field.*

![LogUI — log explorer, light theme](screenshots/explore-light.png)
*Same view in the light theme. Three themes ship (dark / light / sepia), each with its own backdrop.*

![LogUI — compare with the previous period](screenshots/explore-compare.png)
*“compare” overlays the same window one period earlier as muted ghost bars with a solid level cap,
so you can see at a glance whether a spike is new.*

![LogUI — request detail](screenshots/detail.png)
*Request detail: timing, RAM start→peak, SQL count, and the request's own log records (filterable by level & channel).*

![LogUI — files tab](screenshots/files.png)
*Files tab: every discovered `.log` with its size, and a guarded control to shrink a file to its last N MB.*

![LogUI — health tab](screenshots/health.png)
*Health tab (sepia theme): host cards (uptime, load, memory, aggregate disk usage — red over 85%) and the per-filesystem disks table.*

## Features

- Auto-capture: **HTTP requests**, **console commands**, **Monolog records** (level counts + the request's records), **uncaught exceptions**, **SQL queries** (count + slow, when `doctrine/dbal` is present).
- Pure-PHP stats (`memory_get_usage`/`peak` + a Doctrine middleware) — **no PHP extension required**.
- **Telemetry appended to a log file** (`LOGUI@` sentinel), read back by the explorer, where every request and command is a row of type `request` / `command` — no database. Written **directly** (not through Monolog), so it works in prod regardless of `fingers_crossed`/`rotating_file` handlers. Reads are **tail-bounded** (multi-GB logs are fine).
- **Request detail** shows that request's log records (all channels, even ones your file handlers drop), filterable by level/channel.
- **Raw `.log` viewer** (Files tab): multi-format parser (Monolog line **and JSON** formatters, Symfony console logs, nginx access/error, ANSI-stripped) + auto-discovery of Monolog handler files + a scan of your log directories. Each file can be **shrunk to its last N MB** (or emptied) when it has grown too large — a guarded, destructive `POST`, gated by `allow_truncate`.
- **Log explorer** — *the landing page* (`/_logui`): a **search box**, a **zoomable time histogram** and a **live tail**, over **one file or all of them at once**.
  - **Search first**: type `payment failed` and get the lines that say so — **case-insensitive**, message *and* context, regex syntax treated as literal text. A **`regexp` checkbox** turns the same box into a pattern search when you want one. **Select any text in the logs** and a one-click *Grep* button offers to search for it.
  - **LogQL is opt-in**, folded behind a link under the search box: `{file="var/log/dev.log", level="error"} |= "payment" != "healthcheck" | json | status >= 500` — stream selector (`=`, `!=`, `=~`, `!~`), line filters (`|=`, `!=`, `|~`, `!~`), `| json`, and label filters (`| status >= 500`).
  - **Fields panel** (left): one collapsible group per field — `type`, `level`, `channel`, `file` — listing the values that actually occur with their counts and share of the matches. Tick to filter. Built from the current result set, not a hard-coded list, and ANDed with the query instead of rewriting it.
  - **Correlation pivots**: every record is stamped with the id of the request that wrote it (`extra.logui_id`, searchable). Expand a log line → *open request*; expand a request → *logs of this request* (fills the search with the id) or *around this moment* (the window ±2 s, no filters).
  - **Request timeline**: an expanded request draws its log records and slow queries on one SVG strip, from `0 ms` to its total duration.
  - **Latency line**: the histogram overlays p50/p95 request latency per bucket on its own scale (bars count lines, the line measures time), with the p95 in the hover tooltip.
  - **Log patterns**: a `patterns` facet groups messages by shape (numbers, ids, dates, quoted strings → `<n>`, `<uuid>`, `<date>`, `<str>`), so the one event firing a thousand times a minute is a single row. Click to keep only that shape.
  - **Context lines**: a `± lines` button on any log line fetches the records immediately around it (located by byte offset, so a stack trace or the request that caused it shows up) — `more` doubles the window up to ±50.
  - **Compare with the previous period**: a `compare` toggle draws ghost bars for the same window one period earlier, a delta in the Records card, and a `new` badge on patterns absent last time.
  - **Readable stack traces**: multi-frame traces (in a message, a context value or an exception) are rendered as a frame list with vendor frames folded away; pick **PhpStorm** or **VS Code** and `file:line` becomes a click that opens your editor.
  - **Saved views & keyboard**: name and store the current view (config `views` or personal, in `localStorage`); navigate with `j`/`k`, expand with `e`, focus search with `/`, pan with `[`/`]`, `?` for the cheat sheet.
  - **Expandable records**: one dense monospace line each; click to unfold every field, with the context pretty-printed and syntax-highlighted. `expand all`, `wrap`, `clear` and a **row-count select** (50 → 1000, default 100) in the panel header. Expanding survives a refresh, including while tailing.
  - **Live starts clean**: pressing *Live* empties the list so the call you are about to make is the only thing on screen; leaving it reloads the window's history.
  - Histogram: bars stacked by level with per-level gradients, **drag to zoom**, click a bar to jump to it, double-click to zoom out, `←/→` to pan. KPI cards above it (records, errors, warnings, rate, query time).
  - Live tail: Server-Sent Events, **rotation-safe**, with automatic fallback to polling when a proxy blocks SSE. An arriving record **slides in tinted by its level** and washes the page in that colour — a burst of errors is visible without reading a line. Honours `prefers-reduced-motion`.
  - Ranged reads use a **binary search on byte offsets**: pulling one hour out of a 261 MB file takes **~120 ms instead of ~35 s**, reading 0.3 % of it — still no index, still no database.
  - Every query runs under a **wall-clock budget** (5 s) and a row cap; anything cut short comes back flagged `truncated` rather than tying up a worker.
  - The current query, window and filters live in the URL, so a view can be pasted to a colleague.
- **JSON-aware messages**: a message that is *text followed by JSON* is detected and the JSON is **pretty-printed, syntax-highlighted and collapsed to ~2 lines** with an expand/collapse toggle (client-side, in both the request detail records and the raw log viewer).
- **Dashboard UI**: clickable stats (general counts), method column, filters (level/type/method/search), pagination (100/page), and **dark / light / sepia** themes.
- **Health tab** *(read-only host metrics)*: uptime, load, memory, disks, network interfaces, **top processes** (`ps`), optional CPU temperature/throttle (Raspberry Pi), `vnstat` bandwidth, Docker containers and configured systemd services. Every probe **degrades gracefully** — anything unavailable on the host is simply not shown.
- **Security**: open in `dev`/`test`; in production it is **fail-closed** (password, or delegated to your firewall). Sensitive context keys are **redacted** before writing.

## Requirements

- PHP **8.2+**
- Symfony **6.4 / 7.x / 8.x** (HttpKernel, Console, HttpFoundation, Config, DependencyInjection, Routing, EventDispatcher)
- **TwigBundle** (the UI is rendered with Twig) — pulled in automatically
- Monolog **3**
- *(optional)* `doctrine/dbal` ^3.7|^4.0 — enables SQL query counting

## Install (Symfony)

LogUI ships a Flex recipe, so the quickest install is to point your app at this repo as a custom
recipe endpoint and require the package — the bundle, route and config are wired automatically, and
a random `LOGUI_PASSWORD` is generated into `.env` (override it in `.env.local` for production).

**1. Add the recipe endpoint** to your app's `composer.json`:

```json
{
    "extra": {
        "symfony": {
            "allow-contrib": true,
            "endpoint": [
                "https://raw.githubusercontent.com/aleblanc/logui/main/index.json",
                "flex://defaults"
            ]
        }
    }
}
```

**2. Require the package** — the recipe runs on install:

```bash
composer require aleblanc/logui
```

This auto-registers the bundle, creates `config/routes/log_ui.yaml` + `config/packages/log_ui.yaml`,
and writes a generated `LOGUI_PASSWORD` to `.env` (a default, like `APP_SECRET`; for production put a
real secret in `.env.local`, which isn't committed).

**Monolog capture is automatic** — the bundle wires its handler onto every channel for you (no
`monolog.yaml` edit). Disable it with `log_ui.capture_monolog: false` (e.g. in production); see
[Configuration](#configuration).

**Open the UI at `/_logui`** — the default path (configurable via `log_ui.ui_path`). It lands on the
**log explorer**; the captured requests are one tab over, at `/_logui/requests`. It's open in
`dev`/`test`; in `prod` it's fail-closed behind `LOGUI_PASSWORD` (or delegate to your firewall, see
[Access control](#access-control-in-production)).

> **Without the recipe** (or before tagging a release), do it by hand: add
> `Aleblanc\LogUi\Bridge\Symfony\LogUiBundle::class => ['all' => true]` to `config/bundles.php`,
> create `config/routes/log_ui.yaml` with `resource: '@LogUiBundle/config/routes.php'`, and set
> `LOGUI_PASSWORD` yourself. The bundle's config alias is **`log_ui`**. Monolog capture is still
> automatic. Recipe details & the `symfony/recipes-contrib` path: [`recipes/README.md`](recipes/README.md).

## Configuration

All keys are optional with sensible defaults (`config/packages/log_ui.yaml`):

```yaml
log_ui:
    telemetry_file: '%kernel.logs_dir%/%kernel.environment%.log'  # existing log to write/read telemetry
    telemetry_rotation: none           # none | daily | monthly — see below
    telemetry_max_files: 0             # with rotation on, how many dated files to keep (0 = all)
    slow_query_ms: 50                  # SQL slower than this is flagged
    query_budget_seconds: 5.0          # wall-clock ceiling for one explorer query (see below)
    max_records_per_profile: 1000      # cap on records captured per request (bounds line size)
    ui_path: /_logui
    access: password                   # password (default) | delegate
    ui_password: '%env(LOGUI_PASSWORD)%'  # required in prod when access=password
    capture_requests: true             # one telemetry line per HTTP request (the Requests tab)
    capture_commands: true             # same, for console commands
    ignore_commands: []                # commands never profiled — wildcards ok ('scheduler:*')
    ignore_paths: ['/_wdt', '/_profiler']  # never profiled (the UI path is always added)
    capture_monolog: true              # auto-wire the handler onto every Monolog channel
    correlate_records: true            # stamp every record with extra.logui_id (its request)
    discover_monolog: true             # auto-list files from Monolog handlers (Files tab)
    log_dirs: ['%kernel.logs_dir%']    # directories scanned for *.log (Files tab)
    external_logs: []                  # extra .log files to expose (outside the dirs above)
    redact_keys: [password, passwd, secret, token, authorization, api_key]
    health_services: []                # systemd units shown on the Health tab (e.g. [nginx, mariadb, php8.4-fpm])
    allow_truncate: true               # Files tab: a button to shrink a log to its last N MB (destructive)
    views:                             # named explorer views: name => query string
        'Errors 24h': 'levels[]=error&span=86400000'
```

### Mounting under a sub-path

`ui_path` is the address the **browser** uses — every link and AJAX call on the page is built from
it. If you serve LogUI under a prefix (e.g. `/bourse/_logui`), set `ui_path` to that full path **and**
make sure the routes answer there too, so the two agree:

```yaml
# config/packages/log_ui.yaml
log_ui:
    ui_path: /bourse/_logui
```
```yaml
# config/routes/log_ui.yaml — mount the routes under the same prefix
log_ui:
    resource: '@LogUiBundle/config/routes.php'
    prefix: /bourse            # omit if a reverse proxy already strips /bourse before Symfony sees it
```

If a reverse proxy rewrites `/bourse/_logui` → `/_logui` before it reaches Symfony, keep the routes
unprefixed (the default) and only set `ui_path`. The rule of thumb: **`ui_path` must be the URL the
browser hits, and a request to `ui_path` must actually reach the bundle's routes.**

### View your nginx and PHP logs too

LogUI isn't limited to your Symfony/Monolog log. The parser also understands **nginx access logs**
(combined format), **nginx error logs** and **PHP error logs** (`php_errors.log`, FPM), so you can
read and search them in the explorer and the Files tab alongside your app log — same histogram,
facets, live tail and search. Point `external_logs` at the files (or `log_dirs` at a directory that
holds them):

```yaml
log_ui:
    external_logs:
        - /var/log/nginx/access.log
        - /var/log/nginx/error.log
        - /var/log/php8.4-fpm.log      # or your php_errors.log
    # or scan a whole directory for *.log:
    log_dirs: ['%kernel.logs_dir%', '/var/log/nginx']
```

The PHP process must be able to **read** those paths (they are often owned by `root`/`adm` — add
LogUI's user to the right group, or point at a copy your rotation already produces). nginx access
lines are tagged `channel = access` with a level derived from the HTTP status (5xx → error), so
`{channel="access"} | ...` or the level facet filters them like any other record.

### `logui:check` — a log alarm for cron and CI

`logui:check` counts matching records over a recent window and exits non-zero when there are at
least `--min` of them, so a cron line can page you when errors spike:

```bash
# every 5 minutes: alert if 10+ errors were logged in the last 5 minutes
*/5 * * * * php bin/console logui:check '{level="error"}' --since=5m --min=10 || mail -s 'errors' you@example.com
```

Options: a LogQL `query` argument, `--grep=`, `--since=5m` (`s`/`m`/`h`/`d`), `--min=1`,
`--types=`, `--levels=`, `--files=` (comma-separated), `--format=text|json`. It reuses the
explorer's own query engine, so the count matches the UI exactly.

### Why is my log file full of `LOGUI@{…}` lines?

Because that is where LogUI keeps its data. It writes **one `LOGUI@{json}` line per HTTP request
and per console command** — duration, RAM start→peak, SQL count and slow queries, level counts, and
the request's own log records. The explorer reads those lines back as `request`/`command` rows
(header only; the capture is decoded when a row is expanded). That is the whole
"no separate store, no database" design.

They show up in the explorer as rows of type **`request`** and **`command`** — one dense line each
(method, label, status, duration, RAM, SQL), expandable to the full capture. Tick `type` in the
Fields panel, or open the **Requests** tab, which is simply the explorer with
`types[]=request&types[]=command`. The search box and LogQL see the whole capture:
`payment failed` finds the requests that logged it, and
`{type="request"} | json | status >= 500 | duration_ms > 1000` is a profiler query.

On a back-office that is one line per action, and it is the point. On a **public site** it is one
line per page view, and on a **per-minute cron** it is a line a minute whether or not the command
did anything — which is how a log file reaches a gigabyte. Three ways to cut it, from surgical to
blunt:

```yaml
log_ui:
    ignore_commands: ['scheduler:*']   # the usual culprit: a cron tick that does nothing
    ignore_paths: ['/health', '/ping'] # noisy endpoints (the UI path is always excluded)

    capture_requests: false            # no HTTP profiling at all
    capture_commands: false            # no console profiling at all
```

Turning capture off leaves **the log explorer and the Files tab working exactly as before** — they
read your ordinary log files and never needed the telemetry. You lose the request and command rows, nothing
else.

#### Give the telemetry its own file, with a retention

If you want to *keep* the Requests tab but stop the telemetry inflating your application log, move
it to its own file and let LogUI rotate it:

```yaml
log_ui:
    telemetry_file: '%kernel.logs_dir%/logui.log'
    telemetry_rotation: daily      # logui.log → logui-2026-10-13.log
    telemetry_max_files: 30        # a month of history; older files are deleted
```

Pruning happens the first time a new day is seen, so there is no cron to set up. The Requests tab
reads across the dated files as one history, newest first, under a single memory budget — thirty
days of files does not mean thirty times the memory.

**Only files LogUI itself named are ever deleted.** The name must match the pattern *and* its date
part must parse back to the exact format LogUI writes, so `logui-backup.log`,
`logui-2026-13-99.log`, a `.gz` left by logrotate, and the undated base file are all untouchable.

Rotation is `none` by default, and you should **leave it that way while `telemetry_file` is your
application's own log** (`prod.log`, `dev.log`): rotating that file is logrotate's job, not a
library's.

### "Partial result" in the explorer

Each explorer query runs under a wall-clock ceiling (`query_budget_seconds`, **5 s** by default).
When it runs out, the scan stops where it is and the chart says so: everything shown is real, but
the **oldest part of the window was not read**.

It is a backstop, not a tuning knob — without it one person sweeping a month across fifty files
can hold a PHP worker for minutes. That is why the default is low.

If you hit it legitimately (a slow disk, many files, 14- or 28-day ranges), you have three options,
in order of cheapness:

1. **Narrow the time range** — ranged reads binary-search the byte offsets, so a shorter window
   genuinely reads fewer bytes.
2. **Untick files** in the Fields panel — fewer files to walk.
3. **Raise `query_budget_seconds`** — do this knowing it is a denial-of-service ceiling you are
   lifting. Fine behind `delegate` on an internal network; think twice if the UI is public.

Adding a search term does **not** help: the budget is wall-clock and the cost is in the bytes read,
not in the matching — the same file is walked either way.

### Access control in production

In `dev`/`test` the UI is open. In `prod` it is **fail-closed** — choose how to unlock it:

**`access: password`** (default) — serves nothing unless `LOGUI_PASSWORD` is set (the recipe
generates one in `.env`; put a **real, dedicated** secret in `.env.local` for production — never reuse
`APP_SECRET`). `ui_password` defaults to `%env(LOGUI_PASSWORD)%`, so it works out of the box. **Log in**
by sending that password on the request:

```bash
# HTTP header (preferred — doesn't end up in access logs)
curl -H "X-LogUI-Password: <your-password>" https://example.com/_logui

# or query string (browser)
https://example.com/_logui?_pw=<your-password>
```

On a successful login LogUI sets a **session cookie** (`logui_auth`, HttpOnly, SameSite=Lax, scoped
to `ui_path`, signed with an HMAC keyed on the password — the cookie never carries the password
itself). The token embeds a server-enforced 8 h expiry covered by the signature, so it cannot be
extended by tampering, and changing the password invalidates every outstanding token. You supply
`?_pw=` once; **navigation and the explorer's XHR calls then work without it**.

Two things worth knowing before you rely on `password` in production:

- **There is no rate limiting on the password.** LogUI keeps no store, so it has nowhere to count
  attempts. If the UI is reachable from the internet, put a limiter in front of it (nginx
  `limit_req`, your WAF) or use `delegate`.
- **A live tail holds one PHP worker** for up to 5 minutes per open tab (Server-Sent Events). On a
  small php-fpm pool, a handful of forgotten LogUI tabs can starve the application. Size the pool
  accordingly, or keep the UI off the public internet.

For a fully integrated production console (your own login, roles), `delegate` is still the cleaner
choice.

**`access: delegate`** (recommended in prod) — LogUI gates nothing and trusts your own security.
You log in with your normal app session, and navigation just works. Protect the route in
`security.yaml`:

```yaml
access_control:
    - { path: ^/_logui, roles: ROLE_ADMIN }
```

## Health tab

Read-only host metrics at `/_logui/health`. Every metric is gathered on demand and **degrades
gracefully**: when the source file/command is missing, that card or section is simply **not rendered**
(nothing to configure to "turn it off" on an unsupported host).

### Top cards

| Card | Shows | Source | Colour |
|---|---|---|---|
| **Host** | machine model (or OS family) | `/proc/cpuinfo` (`Model:`), else `PHP_OS_FAMILY` | — |
| **Uptime** | human uptime (`10j 20h 0min`) | `/proc/uptime` | — |
| **Temperature** | CPU temp (Raspberry Pi) | `vcgencmd measure_temp`, else `/sys/class/thermal/thermal_zone0/temp` | green <60 °C, amber <70 °C, red ≥70 °C |
| **Load (1/5/15 min)** | load averages + CPU count + bar | `/proc/loadavg`, `/proc/cpuinfo` | green; amber > 70% of cores; red > cores |
| **Memory** | used %, used/total + cache, bar | `/proc/meminfo` | green <70%, amber <85%, red ≥85% |
| **Disk** | aggregate used/size across all real filesystems, bar | sum of `df` | green <70%, amber <85%, **red ≥85%** |

### Sections

| Section | Shows | Source | Notes |
|---|---|---|---|
| **Power / Throttling** | under-voltage / frequency-capped / throttled / soft-temp flags (current + since-boot) | `vcgencmd get_throttled` | Raspberry Pi only; "OK" when clean |
| **Disks** | per-filesystem: mount, source, FS, used, size, usage % + bar | `df -B1 --output=source,fstype,size,used,avail,pcent,target` | skips `tmpfs`/`devtmpfs`/`overlay`/`udev`/`squashfs`; same green/amber/red thresholds |
| **Top processes** | up to **20** processes by CPU: PID, user, %CPU, %MEM, RSS, command | `ps -eo pid,user,pcpu,pmem,rss,comm --sort=-pcpu` | %CPU amber ≥40, red ≥80 |
| **Network interfaces** | name, state, IPv4 addresses, MAC | `ip -j addr show` | skips `lo`, `veth*`, `br-*` |
| **Network usage** | per-interface ↓rx / ↑tx / total, and current month *total → projected estimate* (+ % of month elapsed) | `vnstat --json` | hidden entirely when vnstat is absent |
| **Docker** | container, image, status, live **CPU%** and **memory (usage / limit)** | `docker ps -a` + `docker stats --no-stream` | CPU/memory shown only when `docker stats` succeeds |
| **Services** | configured systemd units with their state badge | `systemctl is-active <unit>` | only the units listed in `health_services` (empty ⇒ section hidden) |

Configure the monitored systemd units in `config/packages/log_ui.yaml`:

```yaml
log_ui:
    health_services: [nginx, mariadb, php8.4-fpm]
```

## How it works

A request-scoped holder is opened on `kernel.request`, fed log records by a Monolog handler and
exceptions by the kernel exception event, then finalized on `kernel.terminate`, which **appends a
single `LOGUI@{json}` line directly to `telemetry_file`** (not through Monolog — so it's immune to
`fingers_crossed` buffering and `rotating_file` naming, which otherwise swallow telemetry in prod).
Console commands are handled symmetrically via `ConsoleEvents`. The UI reads those lines back
(`TelemetryReader`, tail-bounded) and renders the dashboard with Core's filtering/sorting and
**Twig templates** (under `templates/logui/`, exposed as `@LogUi`). SQL counting is a DBAL middleware,
registered only when Doctrine DBAL is installed.

The telemetry file is a normal append-only log. Reads are tail-bounded, but the file itself grows
over time — **rotate it like any log** (logrotate, or point `telemetry_file` at a path your existing
rotation already covers).

## Architecture

A single package, layered: `Aleblanc\LogUi\Core\*` (framework-agnostic — zero Symfony/Monolog/Doctrine
imports, enforced by a custom PHPStan rule) and `Aleblanc\LogUi\Bridge\Symfony\*` (the bundle wiring).
The capture/storage/query Core stays framework-agnostic; the **UI is Symfony + Twig only for now** — a
future `logui-laravel` adapter would reuse the Core and ship its own (Blade) views.

## Roadmap

- Laravel adapter (the Core is already framework-agnostic).
- Publication on Packagist.

### Ideas under consideration

- **Server health & system stats**: lightweight host metrics next to app telemetry —
  CPU/load, RAM, disk usage/inodes, uptime — read from `/proc` (or `sys_getloadavg()`), no agent.
- **Network usage via vnstat**: parse `vnstat --json` to show daily/monthly bandwidth per interface.
- **GoAccess integration**: surface a GoAccess HTML/JSON report from the parsed access logs
  (the Files tab already discovers nginx access/error logs) for top URLs, visitors, status codes.
- **MCP server**: expose LogUI telemetry to AI agents over the Model Context Protocol
  (query requests/commands/logs, fetch a profile, summarize errors) — read-only, reusing
  `TelemetryReader`.

## Development

```bash
composer install
composer test     # PHPUnit
composer stan     # PHPStan (level 8)
composer cs       # PHP-CS-Fixer (@Symfony)
```

## License

[MIT](LICENSE) © aleblanc

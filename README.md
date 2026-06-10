# 🔎 LogUI

> **Log viewer & lightweight profiler for Symfony.** It plugs into your existing logs — no separate store, no database, no build step. MIT licensed.

LogUI is an embeddable Composer package (in the spirit of Laravel Telescope / the Symfony Profiler)
that adds a small **web UI** to your app to browse requests, console commands and raw log files.

For every HTTP request and console command it captures pragmatic **stats** (duration, RAM
start→end→peak, SQL query count, log-level counts) plus the request's own log records, and writes it
all as **one line into your existing log file** (a `LOGUI@{json}` entry). The UI reads those lines
back. It also reads your **raw `.log` files** directly (Monolog, nginx access/error, …).

**No separate store. No new database. No front-end build.**

## Why (vs. plain log viewers)

`opcodesio/log-viewer` & co. display raw log lines. LogUI goes a step further:

1. **Profiling** — duration / RAM start→end→peak / SQL count **per request and per command**, written into *your* logs.
2. **Dashboard** — clickable stats (critical/error/warning, requests/commands), a method column, level/type/method/search filters, pagination.
3. **Multi-format & file-based** — parses Monolog **and** nginx access/error, on your existing files, usable outside `dev`.

## Features

- Auto-capture: **HTTP requests**, **console commands**, **Monolog records** (level counts + the request's records), **uncaught exceptions**, **SQL queries** (count + slow, when `doctrine/dbal` is present).
- Pure-PHP stats (`memory_get_usage`/`peak` + a Doctrine middleware) — **no PHP extension required**.
- **Telemetry written into your existing logs** (`LOGUI@` sentinel), read back by the UI — no separate store. Reads are **tail-bounded** (multi-GB logs are fine).
- **Request detail** shows that request's log records (all channels, even ones your file handlers drop), filterable by level/channel.
- **Raw `.log` viewer** (Files tab): multi-format parser + auto-discovery of Monolog handler files + a scan of your log directories.
- **Dashboard UI**: clickable stats (general counts), method column, filters (level/type/method/search), pagination (100/page), and **dark / light / sepia** themes.
- **Security**: open in `dev`/`test`; in production it is **fail-closed** (password, or delegated to your firewall). Sensitive context keys are **redacted** before writing.

## Requirements

- PHP **8.2+**
- Symfony **6.4 / 7.x / 8.x** (HttpKernel, Console, HttpFoundation, Config, DependencyInjection, Routing, EventDispatcher)
- Monolog **3**
- *(optional)* `doctrine/dbal` ^3.7|^4.0 — enables SQL query counting

## Install (Symfony)

```bash
composer require aleblanc/logui
```

Register the bundle in `config/bundles.php` (if not auto-registered):

```php
Aleblanc\LogUi\Bridge\Symfony\LogUiBundle::class => ['all' => true],
```

Mount the UI — `config/routes/log_ui.yaml`:

```yaml
log_ui:
    resource: '@LogUiBundle/config/routes.php'
```

Capture Monolog records — add the handler in `config/packages/monolog.yaml`:

```yaml
monolog:
    handlers:
        logui:
            type: service
            id: Aleblanc\LogUi\Bridge\Symfony\Monolog\LogUiHandler
```

Then open **`/_logui`** (in `dev`/`test`). Note: the bundle's config alias is **`log_ui`**
(Symfony snake-cases `LogUiBundle`).

## Configuration

All keys are optional with sensible defaults (`config/packages/log_ui.yaml`):

```yaml
log_ui:
    telemetry_file: '%kernel.logs_dir%/%kernel.environment%.log'  # existing log to write/read telemetry
    slow_query_ms: 50                  # SQL slower than this is flagged
    max_records_per_profile: 1000      # cap on records captured per request (bounds line size)
    ui_path: /_logui
    access: password                   # password (default) | delegate
    ui_password: '%env(LOGUI_PASSWORD)%'  # required in prod when access=password
    ignore_paths: ['/_wdt', '/_profiler']  # never profiled (the UI path is always added)
    discover_monolog: true             # auto-list files from Monolog handlers (Files tab)
    log_dirs: ['%kernel.logs_dir%']    # directories scanned for *.log (Files tab)
    external_logs: []                  # extra .log files to expose (outside the dirs above)
    redact_keys: [password, passwd, secret, token, authorization, api_key]
```

### Access control in production

- **`access: password`** (default) — open in `dev`/`test`; **fail-closed** in production: it serves
  nothing unless `LOGUI_PASSWORD` is set. Use a **dedicated** password — never your `APP_SECRET`.
- **`access: delegate`** — LogUI gates nothing and trusts your own security. Protect the route
  yourself, e.g. in `security.yaml`:
  ```yaml
  - { path: ^/_logui, roles: ROLE_ADMIN }
  ```
  Recommended when your app already has authentication.

## How it works

A request-scoped holder is opened on `kernel.request`, fed log records by a Monolog handler and
exceptions by the kernel exception event, then finalized on `kernel.terminate`, which writes a single
`LOGUI@{json}` line through your logger. Console commands are handled symmetrically via `ConsoleEvents`.
The UI reads those lines back (`TelemetryReader`, tail-bounded) and renders the dashboard with Core's
filtering/sorting and a dependency-free PHP template renderer. SQL counting is a DBAL middleware,
registered only when Doctrine DBAL is installed.

Because the telemetry lives in your normal log stream, **your own log rotation governs retention** —
LogUI keeps no files of its own.

## Architecture

A single package, layered: `Aleblanc\LogUi\Core\*` (framework-agnostic — zero Symfony/Monolog/Doctrine
imports, enforced by a custom PHPStan rule) and `Aleblanc\LogUi\Bridge\Symfony\*` (the bundle wiring).
This keeps extraction of a future `logui-laravel` adapter straightforward.

## Roadmap

- Laravel adapter (the Core is already framework-agnostic).
- Publication on Packagist.

## Development

```bash
composer install
composer test     # PHPUnit
composer stan     # PHPStan (level 8)
composer cs       # PHP-CS-Fixer (@Symfony)
```

## License

[MIT](LICENSE) © aleblanc

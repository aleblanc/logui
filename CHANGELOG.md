# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres
to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

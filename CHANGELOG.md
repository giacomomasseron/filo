# Changelog

All notable changes to filo are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/). From 1.0.0 on,
filo follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html);
the README's "Public API" section says what that covers.

## [Unreleased]

### Upgrading from 0.2

- **Closure names changed on PHP 8.1–8.3.** They used to be `{closure}`
  there; closures are now named the PHP 8.4 way on every version, e.g.
  `{closure:App\Repo::find():12}`. Replace `{closure}` in `toCall()`-style
  patterns and breakpoints with `{closure*`, or with the closure's full name.
- **Long-running runtimes** (Octane, RoadRunner, FrankenPHP workers): call
  `\Filo\Tracer::cycle()` instead of `\Filo\Collector::cycle($dir)`.
- **Restart PHP once** (PHP-FPM, Herd, Valet) if you traced with opcache
  enabled on 0.2: older versions could leave instrumented code in the opcache.

### Added

- `Filo\Tracer::cycle()`, the public per-request boundary for long-running
  runtimes. It does nothing while tracing is off.
- A JSON Schema for the trace format, `docs/trace-v1.schema.json`, published
  at <https://giacomomasseron.github.io/filo/trace-v1.schema.json>.
- Breakpoints can target a single closure by its name.
- Support for Pest 4 and 5 and PHPUnit 12 and 13. CI runs every Pest major
  from 2 to 5 on PHP 8.2 to 8.5, on Windows and macOS too, and serves a
  fresh Laravel app over `php -S` with tracing turned on and off.
- `filo.json` in the project root for settings (`exclude`, `keep`,
  `breakTimeout`), so people who turn filo on with `.filo-on` can configure
  it and a team can share one configuration. Env vars still win.
- Trace retention: only the newest 200 request traces are kept (`keep`,
  `FILO_KEEP`; 0 keeps them all). Per-test artifacts are never pruned.
- A "Public API" section in the README, and `SECURITY.md`.

### Changed

- Hooks are spliced into the original source instead of re-printing the
  file, so while tracing is on, exception lines, `__LINE__` and stack traces
  match your code, test files included.
- Closures are named `{closure:<enclosing scope>:<line>}` on every PHP
  version (breaking on 8.1–8.3, see above).
- The event cap follows `memory_limit`: events get at most a quarter of it,
  and recording stops once the process passes 90% of it. A trace cut short
  is marked `capped: true`.
- `filo breaks` lists disabled and file:line breakpoints too, and
  `filo break` on a disabled breakpoint re-enables it instead of adding a
  duplicate.
- Every class outside `Filo\Testing` is marked `@internal`.
- Trace files are named down to the microsecond, e.g.
  `20260915-101530-123456-ab12.json`.
- PHPStan checks the code at level 8 in CI.

### Fixed

- Opcache no longer has to be disabled by hand: filo switches it off for
  traced requests only, so a warm cache can't bypass tracing and
  instrumented code is never cached for untraced requests.
- A runaway loop under a tight `memory_limit` no longer crashes the request
  inside filo.
- `filo break` and `filo unbreak` no longer delete the disabled and
  file:line breakpoints created in the web UI.

### Security

- The viewer refuses requests whose `Host` isn't `127.0.0.1`, `localhost` or
  `[::1]`, which blocks DNS-rebinding attacks from pages open in your browser.
- Arguments marked `#[\SensitiveParameter]` appear in breakpoint snapshots as
  `[SensitiveParameter]` instead of their value.

### Removed

- The IDE's `.idea/` files are no longer in the repository or the Composer
  package.

## [0.2.0] - 2026-09-10

### Added

- A global per-test time limit: a `threshold` parameter on `TraceExtension`,
  enforced by the `EnforcesThreshold` trait. A test can set its own limit
  with `$this->threshold()`.

### Fixed

- The first request or test to touch a file is no longer billed for the
  time filo spends instrumenting it.

## [0.1.0] - 2026-09-08

First release.

- Zero-extension call tracing: a `file://` stream wrapper instruments every
  included file outside `vendor/`, and each request writes a JSON trace to
  `.filo/traces/`. Turn it on with a `.filo-on` file or `FILO_ENABLED=1`.
- Function-entry breakpoints, controlled from the CLI (`filo break`,
  `pending`, `show`, `continue`) or the web viewer, auto-continuing after
  `FILO_BREAK_TIMEOUT` seconds.
- A local web viewer: `filo serve`.
- Testing integration: `Recorder::capture()`, Pest expectations
  (`toRunUnder`, `toCall`, `toCallOnce`, `toNotCall`), the PHPUnit
  `FiloAssertions` trait, and per-test trace artifacts through
  `TraceExtension` and `#[Traced]`.

[Unreleased]: https://github.com/giacomomasseron/filo/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/giacomomasseron/filo/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/giacomomasseron/filo/releases/tag/v0.1.0

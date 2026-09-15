# CLAUDE.md — filo

Zero-extension PHP call tracer (Xdebug alternative). Userland instrumentation:
a `file://` stream wrapper intercepts every include, parses it
(nikic/php-parser ^5), splices timing/breakpoint hooks into the source text
(never re-printed, so lines don't move), and serves the result from memory. Original files are NEVER touched; instrumented
copies live only in a cache. Composer package `giacomomasseron/filo`,
namespace `Filo\`, PHP ^8.1. "filo" = Italian for thread (Ariadne's thread).

## Status: CI (.github/workflows/ci.yml) runs PHP 8.2–8.5 × Pest 2–5 (PHPUnit 10–13) on Linux, plus Windows, macOS, a runtime-only 8.1 job and a Laravel app over php -S (`.github/scripts/laravel-smoke.php`). New Pest/PHPUnit major ⇒ widen require-dev and add matrix rows.

## Verification pass (do this first)

1. `composer install`
2. `php -l` every file in `src/`, `bootstrap.php`, `bin/filo`, `server/index.php`
3. `FILO_ENABLED=1 vendor/bin/pest` — all green
4. `FILO_ENABLED=1 php -d opcache.enable_cli=0 examples/smoke.php` — must print 3 PASS lines
5. Known-risk spots, in order of suspicion:
   - token brace matching in `HookVisitor::bodyBraces()` and `Instrumenter`'s
     re-parse of the spliced source (`createForHostVersion()`)
   - `STREAM_OPEN_FOR_INCLUDE` (value 128) actually firing in
     `IncludeStreamWrapper::stream_open()` on the target SAPI
   - `stream_stat()` on the `php://memory` handle serving instrumented includes
6. Breakpoints end-to-end: `examples/break-demo.php` (two terminals, see its header)
7. `vendor/bin/filo serve` — the UI (`server/ui/index.html`) has no automated
   tests; verify the trace list renders, the call tree expands, continue
   releases a pause

## Architecture invariants (do not break these)

- **Wrapper re-entrancy**: every real filesystem touch inside
  `IncludeStreamWrapper` MUST go through `self::native()` (restore real
  wrapper → op → re-hook). A missed one = infinite recursion.
- **Eager loading**: every `Filo\` class is `require_once`'d in
  `bootstrap.php` BEFORE the wrapper registers. New src file ⇒ new require
  there, or autoloading it recurses through the wrapper.
- **`$openedPath` stays unset** for instrumented includes so `__FILE__`/
  `__DIR__` keep pointing at the real source, never the cache.
- **Fail open**: any parse/instrumentation failure ⇒ serve the ORIGINAL file.
  Filo must never be able to take an app down.
- **Cache key** = wrapper VERSION + Instrumenter VERSION + realpath + mtime.
  Any change to the injected code ⇒ bump `Instrumenter::VERSION`.
- **Hooks are spliced, never re-printed**: HookVisitor plans text insertions
  right after each body's `{` and right before its `}`, with no newlines, so
  every line keeps its number (`tests/Integration/LineNumbersTest.php`).
  Instrumenter re-parses the result and fails open if it doesn't parse.
- **Closure names are baked literals, PHP 8.4 style**:
  `{closure:<enclosing scope>:<line>}` on every PHP version (HookVisitor's
  scope stack; arrow fns count as scopes; top level = the file's realpath).
  `ClosureNamesTest` compares them with PHP's own `__METHOD__` on 8.4+.
  Anonymous classes read `class@anonymous` (PHP's name has a path + counter).
- **Public API** = `Filo\Testing` + `Tracer::cycle()` + CLI, env vars, file
  formats and the HTTP API (README "Public API"). Every other class is
  `@internal` — mark new src classes too.
- **Trace format contract** = `docs/trace-v1.schema.json`, validated against
  every producer by `TraceSchemaTest`. Changing a field ⇒ change the schema
  (additive only within v1).
- **Traces always live in `<project>/.filo/traces`** (breaks in `…/breaks`),
  via `Tracer::outputDir()` — the only place that knows the path. Not
  configurable by design; `.filo/` must be gitignored.
- **Self-exclusion**: the package dir and cache dir are always excluded from
  instrumentation (see `Tracer::start`). Test fixtures must live OUTSIDE the
  repo (temp dir) — see `examples/smoke.php`.
- **Opcache is off for traced requests**: `Tracer::start()` calls
  `ini_set('opcache.enable', '0')` (allowed at runtime, off-only, until the
  request ends). Without it a warm cache bypasses the wrapper, and
  instrumented code gets cached for untraced requests to run. Covered by
  `tests/Integration/OpcacheTest.php` (php -S shares one opcache like FPM;
  it needs `opcache.file_update_protection=0` for fresh fixture files).
- **Collector caps are fail-open and amortized**: events get at most a
  quarter of memory_limit (~600 B/event incl. flush JSON), and recording
  stops once the process passes 90% of it. `enter()` checks both only when
  `($id & 1023) === 0` — reading the static caps on every call cost ~7%.
- **breakpoints.json has one reader/writer**: `Filo\Breakpoints`, shared by
  bin/filo, server/index.php and Debugger. Parse it nowhere else.
- **Pause time and cache-miss instrumentation time are excluded from
  traces** via `Collector::excludePause()` (epoch shift; callers: `Debugger`,
  `IncludeStreamWrapper::instrumentedCode()`). Don't "fix" timings by
  touching individual events.
- **Root-path arithmetic**: `bootstrap.php` sits at package root ⇒ project
  root is `dirname(__DIR__, 3)`; `src/` files ⇒ `dirname(__DIR__, 4)`. When that
  fails (cwd=public/, path-repo symlink), `findProjectRoot()` walks up
  from SCRIPT_FILENAME dir and cwd until it finds composer.json or .filo/.
  This was already gotten wrong once.
- **Testing module** (`src/Testing/`): framework-free classes (Trace,
  Recorder, Assert, exceptions, Traced, PHPUnit/TestArtifact) are eagerly
  required in `bootstrap.php`; classes that reference PHPUnit/Pest
  (FiloAssertions, EnforcesThreshold, PHPUnit/TraceExtension, Pest/*) are
  autoloaded only.
  `Collector::mark()/since()` are the only collector additions — hot path untouched.
  The global per-test `threshold` is a TraceExtension parameter (kept in
  static state), enforced by the EnforcesThreshold trait's `#[PostCondition]`
  hook: PHPUnit extensions can observe tests but never fail them.
  The Pest plugin `Filo\Testing\Pest\Plugin` is declared in composer.json
  `extra.pest.plugins` and auto-discovered by Pest (root package included) —
  no manual `require` in tests/Pest.php.
- **Own test suite**: `FILO_ENABLED=1 vendor/bin/pest`. Instrumented fixtures
  are written to a temp dir by `tests/Support/TempProject`. The `fixture`
  group is excluded from normal runs and executed by
  `tests/Integration/ArtifactsTest.php` in a child process with
  `FILO_PROJECT_ROOT` set to a temp dir. Likewise the `threshold-fixture`
  group (`tests/Fixtures/Threshold`) is run by
  `tests/Integration/ThresholdTest.php` with a generated config that sets
  `threshold`.

## Decisions already made (don't relitigate casually)

- Framework-agnostic core; Laravel/Symfony adapters later as thin packages.
- Bootstrap via Composer `autoload.files` (accepted cost: front controller
  itself isn't instrumented; `auto_prepend_file` documented as full-coverage mode).
- Zero-command UX: `.filo-on` marker file toggles tracing; env vars are the
  CI alternative. A Laravel `.env` entry cannot work (loads after bootstrap).
- Breakpoints are ENTRY-only, once per request per breakpoint,
  inspect-and-continue via files (poll for `<id>.continue`), auto-timeout
  `FILO_BREAK_TIMEOUT` (120s). No stepping, no eval — Xdebug's territory.
- Web viewer: `filo serve` (php -S, localhost-only). `server/index.php` =
  JSON API (contract in its header comment, public from 1.0) + a minimal
  fallback page. The real UI is the Claude Design export in `server/ui/`;
  it may be replaced, the API may not.
- Trace format v1: flat events `{i,p,fn,file,line,s,e,m}`, ns offsets;
  see README "Trace format". `examples/sample-trace.json` is the fixture;
  `docs/trace-v1.schema.json` (published via GitHub Pages) is the contract.

## Known limitations (documented, not bugs)

Opcache is switched off per traced request automatically, except where an
FPM pool pins `opcache.enable` via `php_admin_value`; preloaded files
(`opcache.preload`) are never traced. Columns (never lines) shift on the
two brace lines of each hooked function.
Arrow functions, native functions, eval'd code = caller self-time.

## Roadmap candidates (phase 3+)

1. ~~opcache coexistence~~ — done: opcache is disabled per traced request.
2. ~~Exact line numbers~~ — done: hooks are spliced into the original source.
3. Long-running runtime adapters (Octane/RoadRunner: `Tracer::cycle()`).
4. Sampling mode (instrument N% of requests) for staging.
5. ~~Designed web UI~~ — shipped in server/ui/ (flat dir, extension
   whitelist in server/index.php). Never serve static files via `return false`
   in the php -S router — it resolves against project root and exposes source.
6. Unit tests for VarExporter, HookVisitor output snapshots, Debugger
   timeout path (descoped from the test-integration plan).

## Conventions

PHP 8.1+ syntax, `declare(strict_types=1)` everywhere, final classes,
static hot paths in `Collector`/`Debugger` (no DI — bootstrap runs before
any container exists). Hot-path code (enter/leave/hit) must stay
allocation-light; measure before adding anything there.

# filo

[![Latest Version on Packagist](https://img.shields.io/packagist/v/giacomomasseron/filo.svg?style=flat-square)](https://packagist.org/packages/giacomomasseron/filo)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/giacomomasseron/filo/ci.yml?branch=main&label=tests&style=flat-square)](https://github.com/giacomomasseron/filo/actions?query=workflow%3ACI+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/giacomomasseron/filo.svg?style=flat-square)](https://packagist.org/packages/giacomomasseron/filo)

Zero-extension PHP call tracer. Userland instrumentation via a `file://`
stream wrapper + AST rewriting (nikic/php-parser). Original files are
never modified; instrumented copies live only in a throwaway cache.

<p align="center"><a href="https://giacomomasseron.github.io/filo/"><strong>Documentation</strong></a></p>

## Install

```bash
composer require --dev giacomomasseron/filo
```

That's it — `bootstrap.php` is registered via Composer's `autoload.files`,
so it runs the moment `vendor/autoload.php` is loaded. It does nothing
unless explicitly enabled.

## Run

There is nothing to run. The tracer hooks in automatically on every
request once enabled — it works the same under Herd, Valet, nginx+FPM,
Apache, `artisan serve`, or plain CLI scripts.

**Enable it:** create an empty `.filo-on` file in your project root
(from your IDE file tree is fine). Browse your app as usual; every
request writes a JSON trace to `.filo/traces/` inside your project
(add `.filo/` to your `.gitignore`). Delete the file to stop tracing.
The check is per-request, so toggling is instant — no restarts.

**Alternative** (CI, docker-compose, one-off CLI runs): set the env var
`FILO_ENABLED=1`. A Laravel `.env` entry does *not* work — it loads
after the tracer bootstraps. Use the marker file for that workflow.

**Opcache:** nothing to do. filo switches opcache off for each traced
request, and only those, so a warm cache can't bypass the tracer and
instrumented code never lands in the cache. The one exception is an FPM
pool that pins `opcache.enable` with `php_admin_value`: that can't be
changed at runtime, so turn opcache off there while tracing.

## Configuration (env vars)

| Var                 | Default               | Meaning                                   |
|---------------------|-----------------------|-------------------------------------------|
| `FILO_ENABLED`    | `0`                   | Master switch                             |
| `FILO_CACHE_DIR`  | `<tmp>/filo-cache`  | Instrumented-file cache                   |
| `FILO_EXCLUDE`    | `vendor`              | Comma-separated path substrings to skip   |

## Tests & CI

filo works inside your test suite once the process is enabled
(`FILO_ENABLED=1 vendor/bin/pest`, or the `.filo-on` marker).

### Performance assertions (no baselines — explicit thresholds only)

**Pest**

```php
expect(fn () => $repo->paginateByUser($user))->toRunUnder(10);          // ms
expect(fn () => $service->list())->toCall('App\Repo::find')->atMost(1);  // N+1 guard
// NB: toCall() alone asserts nothing — always finish with atMost()/atLeast()/times().
expect(fn () => $service->list())->toCallOnce('App\Repo::find');
expect(fn () => $service->list())->toNotCall('App\Mail\*');            // trailing * = prefix glob
```

A chain of filo expectations captures the closure once; later expectations
in the same chain reuse that trace.

`expect()` also accepts a ready `Filo\Testing\Trace` (from
`Filo\Testing\Recorder::capture(fn () => …)`) so one capture can back
several assertions. Function names are the `__METHOD__` form:
`App\Repo::find`, `my_function`, `{closure}`.

**PHPUnit** — `use Filo\Testing\FiloAssertions;` in your test case:

```php
$this->assertRunsUnder(10, fn () => $repo->paginateByUser($user));
$this->assertCallCount('App\Repo::find', atMost: 1, callable: fn () => $service->list());
$this->assertNoCalls('App\Mail\*', fn () => $service->list());
```

Failures name the offender:
`App\Repo::find called 11 times, expected at most 1` /
`took 14.2 ms, limit 10 ms (slowest self-time: App\Repo::find 9.8 ms ×11)`.
If the suite runs without filo enabled, call-based assertions throw
`FiloNotEnabledException` instead of silently passing.

### Trace artifacts per test

Register the extension (Pest reads `phpunit.xml` too):

```xml
<extensions>
  <bootstrap class="Filo\Testing\PHPUnit\TraceExtension"/>
</extensions>
```

Every **failing** test, and every class-based test marked
`#[Filo\Testing\Traced]` (on the method or the class), writes
`.filo/traces/tests/<Class>__<method>.json`. Open them with
`vendor/bin/filo serve`. Pest closure-style tests get artifacts on failure
only (there is nowhere to put an attribute).

### Global threshold

`toRunUnder()` guards one closure. To put a ceiling on *every* test, give
the extension a `threshold` in ms:

```xml
<extensions>
  <bootstrap class="Filo\Testing\PHPUnit\TraceExtension">
    <parameter name="threshold" value="200"/>
  </bootstrap>
</extensions>
```

Then turn enforcement on once. A PHPUnit extension can observe tests but
not fail them, so the check lives in a trait:

```php
// tests/Pest.php
uses(Filo\Testing\EnforcesThreshold::class)->in('Feature', 'Unit');

// or PHPUnit: your base TestCase
abstract class TestCase extends BaseTestCase
{
    use Filo\Testing\EnforcesThreshold;
}
```

A test over the limit fails with
`filo threshold: took 312 ms, limit 200 ms (slowest self-time: App\Repo::find 180 ms ×40)`
and, like any failing test, gets a trace artifact. A test that is slow by
design sets its own limit:

```php
$this->threshold(2000);  // this test may take up to 2 s
$this->threshold(null);  // no limit for this test
```

The clock runs from test preparation (before `setUp`/`beforeEach`) to the
end of the test body. `tearDown`/`afterEach`, breakpoint pauses and filo's
first-include instrumentation don't count, but filo's per-call overhead
does, so leave some headroom. A test that already failed keeps its own
failure. Without filo enabled nothing is enforced, and neither is it under
plain PHPUnit's `--process-isolation`: isolated test processes never see
the extension's settings (Pest doesn't offer that option). An invalid value
(e.g. `200ms`) makes PHPUnit report `Bootstrapping of extension … failed`
with the offending value; trace artifacts keep working.

### Breakpoints in a test

`FILO_ENABLED=1 vendor/bin/pest --filter=checkout` with a breakpoint set
(`vendor/bin/filo break "App\Service\Checkout::charge"`): the test pauses,
inspect with `vendor/bin/filo pending` / `show <id>` / the viewer, then
`continue`. Pause time is excluded from `toRunUnder` measurements. With
`--parallel` or `--process-isolation` several workers may pause at once.

### GitHub Actions

```yaml
- run: FILO_ENABLED=1 vendor/bin/pest
- uses: actions/upload-artifact@v4
  if: failure()
  with: { name: filo-traces, path: .filo/traces/ }
```

Download the artifact, drop it into `.filo/traces/`, run `vendor/bin/filo serve`.

## Trace format (v1)

```jsonc
{
  "version": 1,
  "duration": 12345678,          // ns
  "capped": false,
  "context": { "method": "GET", "uri": "/orders" },
  "events": [
    { "i": 0, "p": -1, "fn": "App\\Http\\Kernel::handle",
      "file": "/app/app/Http/Kernel.php", "line": 41,
      "s": 1200, "e": 8400300, "m": 2097152 }
  ]
}
```

`p` is the parent event id (`-1` = root). `s`/`e` are start/end offsets
in ns from request start. Self-time of a frame = `(e - s) - Σ children`.

`capped: true` means filo stopped recording part-way, so the trace is
incomplete. That happens after 500k events, once events fill a quarter of
`memory_limit`, or once the whole process passes 90% of `memory_limit`.
Tracing can't be what runs a request out of memory.

## Breakpoints

Function-entry breakpoints, controlled by files — no daemon, no IDE
protocol. Works alongside tracing.

```bash
vendor/bin/filo break "App\\Services\\OrderService::listForUser"
```

Then trigger the code path (browse the page, run the command). The
request **freezes** at that function's entry. From another terminal:

```bash
vendor/bin/filo pending          # see paused requests
vendor/bin/filo show <id>        # inspect arguments, $this, locals
vendor/bin/filo continue <id>    # release it (or: continue --all)
```

Rules of engagement:

- Entry breakpoints only: you see the arguments (and `$this` for
  instance methods) as the function begins. No stepping, no eval —
  that's Xdebug territory, deliberately.
- Each breakpoint pauses **once per request** (so a breakpoint inside
  a loop doesn't pause 500 times).
- A paused request auto-continues after `FILO_BREAK_TIMEOUT` seconds
  (default 120) — a forgotten breakpoint can never hang a request
  forever.
- Pause time is **excluded from trace timings**: while you inspect,
  the timeline clock stops, so breakpoints don't pollute your
  flamegraph.
- Breakpoints live in `.filo/breakpoints.json` in the project root;
  paused-request snapshots in `<output>/breaks/`. The web UI reads and
  writes the same files — the CLI and UI are interchangeable.

## Web viewer

```bash
vendor/bin/filo serve        # http://127.0.0.1:8090
```

A zero-dependency local viewer (PHP built-in server, single file):
trace list, call tree with self-times, top-functions table, and live
paused-request panel with continue buttons. Localhost-only by design —
traces contain paths and variable values; never expose the port.

The built-in page is a functional placeholder. To install a designed UI
(e.g. exported from Claude Design), copy its files into `server/ui/`
(entry point `index.html`, assets flat in the same directory). When that
directory exists it is served instead of the placeholder — no code
changes. The UI must call the JSON API with relative paths
(`/api/traces`, `/api/breaks`, `/api/breakpoints`; contract documented
at the top of `server/index.php`). Delete `server/ui/` to fall back.

## Known limitations (v1, by design)

- Files preloaded with `opcache.preload` never pass through the
  wrapper, so they aren't traced.
- Files loaded before `vendor/autoload.php` (the front controller) are
  not instrumented. Use `auto_prepend_file` pointing at
  `vendor/giacomomasseron/filo/bootstrap.php` for full coverage.
- Native functions, `eval`'d code and arrow functions show up as
  self-time of their caller.
- Line numbers inside instrumented files drift (standard pretty
  printer); trace line numbers are correct — they're baked in from the
  original AST.
- Long-running runtimes: call `\Filo\Collector::cycle($dir)` per
  request instead of relying on shutdown flush.

# filo

[![Latest Version on Packagist](https://img.shields.io/packagist/v/giacomomasseron/filo.svg?style=flat-square)](https://packagist.org/packages/giacomomasseron/filo)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/giacomomasseron/filo/ci.yml?branch=main&label=tests&style=flat-square)](https://github.com/giacomomasseron/filo/actions?query=workflow%3ACI+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/giacomomasseron/filo.svg?style=flat-square)](https://packagist.org/packages/giacomomasseron/filo)

Zero-extension PHP call tracer. Userland instrumentation via a `file://`
stream wrapper that splices hooks into your code as it loads (parsed with
nikic/php-parser), keeping every line where it was. Original files are
never modified; instrumented copies live only in a throwaway cache.

<p align="center"><a href="https://giacomomasseron.github.io/filo/"><strong>Website</strong></a> &nbsp;·&nbsp; <a href="https://giacomomasseron.github.io/filo/demo.html"><strong>Demo</strong></a></p>

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
(from your IDE file tree is fine, or run `vendor/bin/filo on`). Browse
your app as usual; every request writes a JSON trace to `.filo/traces/`
inside your project (add `.filo/` to your `.gitignore`), and the newest
200 are kept. Delete the file (or run `vendor/bin/filo off`) to stop
tracing. The check is per-request, so toggling is instant — no restarts.
`vendor/bin/filo doctor` checks the whole setup.

**Alternative** (CI, docker-compose, one-off CLI runs): set the env var
`FILO_ENABLED=1`. A Laravel `.env` entry does *not* work — it loads
after the tracer bootstraps. Use the marker file for that workflow.

**Opcache:** nothing to do. filo switches opcache off for each traced
request, and only those, so a warm cache can't bypass the tracer and
instrumented code never lands in the cache. The one exception is an FPM
pool that pins `opcache.enable` with `php_admin_value`: that can't be
changed at runtime, so turn opcache off there while tracing.

## Configuration

Each setting comes from its environment variable, else from `filo.json`
in your project root, else its default. `filo.json` is how you configure
filo when you turn it on with `.filo-on` (Herd and Valet don't make env
vars easy), and how a team shares one configuration: commit it.

```json
{
    "include": ["vendor/acme/billing"],
    "exclude": ["/vendor/", "/storage/"],
    "keep": 200,
    "breakTimeout": 120
}
```

| `filo.json`    | Env var              | Default            | Meaning                                                    |
|----------------|----------------------|--------------------|------------------------------------------------------------|
| `include`      | `FILO_INCLUDE`       | `[]`               | Paths to trace even though `exclude` matches them, see below |
| `exclude`      | `FILO_EXCLUDE`       | `["/vendor/"]`     | Path substrings to skip (env var: comma-separated)         |
| `keep`         | `FILO_KEEP`          | `200`              | Request traces to keep, older ones are deleted (0 = all)   |
| `breakTimeout` | `FILO_BREAK_TIMEOUT` | `120`              | Seconds before a paused request continues on its own       |
|                | `FILO_ENABLED`       | `0`                | Master switch (the `.filo-on` file is the alternative)     |
|                | `FILO_CACHE_DIR`     | `<tmp>/filo-cache` | Instrumented-file cache                                    |
|                | `FILO_PROJECT_ROOT`  | auto-detected      | Project root, for when detection picks the wrong folder    |

A value filo can't use (a typo, a wrong type) is ignored, never fatal.
`vendor/bin/filo doctor` shows every setting, where it comes from, and
any problem.

### Tracing vendor code

`vendor/` isn't traced by default, so its calls cost nothing and don't
show up. To see inside a package, include its folder (relative to the
project root, or absolute), or a single file:

```json
{ "include": ["vendor/laravel/framework/src/Illuminate/Database"] }
```

Included code is traced like your own: its calls show up in traces,
breakpoints on its functions pause, and tests can count them, e.g. to
catch N+1 queries at the source:

```php
expect(fn () => $orders->forUser($user))
    ->toCall('Illuminate\Database\Connection::select')->atMost(1);
```

`include` wins over `exclude`; filo itself, its cache and its parser are
never traced. Each included call costs what your own do (see
[Overhead](#overhead)), and the first traced request after a change
instruments every included file it loads, so include the folders you
need rather than all of `vendor/`.

## Command line

```bash
vendor/bin/filo on | off       # turn tracing on/off for this project (.filo-on)
vendor/bin/filo doctor         # check the setup: project root, settings, git, opcache
vendor/bin/filo serve [port]   # the local web viewer, see below
vendor/bin/filo export         # the latest trace, for PhpStorm or speedscope, see below
vendor/bin/filo clear          # delete traces and test artifacts
vendor/bin/filo --version
```

The breakpoint commands are under [Breakpoints](#breakpoints).

## Exporting traces

`filo export` writes a trace in a format other tools open:

```bash
vendor/bin/filo export                                        # the latest request trace, as cachegrind
vendor/bin/filo export --format=speedscope                    # the same, for speedscope
vendor/bin/filo export tests/App_CheckoutTest__testPay.json   # a test's trace
vendor/bin/filo export 20260915-101530-123456-ab12.json -o checkout.out
```

The file goes to `.filo/exports/` unless `-o` names one (`-o -` writes it
to stdout).

- **cachegrind** is what Xdebug's profiler writes: open it in PhpStorm
  (*Tools → Analyze Xdebug Profiler Snapshot*), KCachegrind or QCachegrind.
  It sums the trace up per function: self time, total time, call counts,
  and who called whom.
- **speedscope**: drop the file on <https://www.speedscope.app>, where it
  stays in your browser. You get the whole timeline as a flame chart, plus
  left-heavy and sandwich views.

Times include filo's own cost per traced call (see [Overhead](#overhead)),
and time spent in untraced code (`vendor/`, PHP's own functions, `fn`
closures) counts as the caller's self time. A trace cut short (`capped`)
exports what it recorded.

## Tests & CI

filo works inside your test suite once the process is enabled
(`FILO_ENABLED=1 vendor/bin/pest`, or the `.filo-on` marker), with Pest 2
to 5 and PHPUnit 10.5 to 13.

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
several assertions. Function and method names are the `__METHOD__` form
(`App\Repo::find`, `my_function`; a trait method keeps the trait's name).
Closures are named after where they are declared, the PHP 8.4 way, on
every PHP version: `{closure:App\Repo::find():12}`, or
`{closure:/path/to/file.php:3}` at the top level of a file. `{closure*`
matches them all.

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

The full contract is the JSON Schema in
[`docs/trace-v1.schema.json`](docs/trace-v1.schema.json), also published at
`https://giacomomasseron.github.io/filo/trace-v1.schema.json`.

## Breakpoints

Function-entry breakpoints, controlled by files — no daemon, no IDE
protocol. Works alongside tracing.

```bash
vendor/bin/filo break "App\\Services\\OrderService::listForUser"
```

A closure is targeted by its trace name, e.g.
`"{closure:App\\Services\\OrderService::listForUser():42}"`. Or give a file
and line, relative to the project root or absolute:

```bash
vendor/bin/filo break app/Services/OrderService.php:42
```

That pauses when the innermost function or closure containing line 42 is
entered: breakpoints stop at a function's entry, never in the middle of
one, and a line outside any function never pauses.

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
- Breakpoints fire only in traced code: to pause inside a package in
  `vendor/`, add it to `include` (see [Tracing vendor code](#tracing-vendor-code)).
- Each breakpoint pauses **once per request** (so a breakpoint inside
  a loop doesn't pause 500 times).
- A paused request auto-continues after `FILO_BREAK_TIMEOUT` seconds
  (default 120) — a forgotten breakpoint can never hang a request
  forever.
- Pause time is **excluded from trace timings**: while you inspect,
  the timeline clock stops, so breakpoints don't pollute your
  flamegraph.
- Breakpoints live in `.filo/breakpoints.json` in the project root;
  paused-request snapshots in `.filo/traces/breaks/`. The web UI reads
  and writes the same files, so the CLI and UI are interchangeable (the
  CLI leaves the UI's disabled and file:line entries alone).
- Arguments marked `#[\SensitiveParameter]` appear in snapshots as
  `[SensitiveParameter]`, never with their value.

## Web viewer

```bash
vendor/bin/filo serve        # http://127.0.0.1:8090
```

A local viewer on PHP's built-in server: a flamegraph, the call tree with
self times, the top functions by self time, and a panel of paused requests
with their variables and a continue button. Localhost-only by design —
traces contain paths and variable values; never expose the port. Requests
whose `Host` isn't `127.0.0.1`, `localhost` or `[::1]` are refused, which
blocks DNS-rebinding attacks from pages open in your browser. See
[SECURITY.md](SECURITY.md).

The UI is a single bundled file, `server/ui/index.html`, that only talks to
the JSON API (`/api/traces`, `/api/breaks`, `/api/breakpoints`; contract at
the top of `server/index.php`). The trace list carries summaries only; a
trace's events load when you open it, so big traces don't slow the list
down. If `server/ui/` is missing, a minimal built-in page is served instead.

## Overhead

Tracing is for development: a traced request runs slower, and this is how
much. `php examples/bench.php` measures it on your machine, and CI
publishes the same tables on every run's summary page.

Measured on Linux (Ubuntu 24.04 on WSL2, AMD Ryzen 5 3600XT, PHP 8.3):

| What | Cost |
|---|---|
| Each traced call (function, method or closure) | about 0.8 µs, and 400 bytes until the request ends |
| Writing the trace when the request ends | about 0.65 µs per call |
| Including a file the first time, or after it changes: filo parses and instruments it, then caches the result | about 6.6 ms for a 190-line file |
| Including it again, from that cache | about 0.15 ms more than plain PHP |
| Tracing off | about 27 µs per request with opcache: two env vars and at most three file checks |

So 10,000 calls of your own code add about 8 ms while they run, 6.5 ms to
write the trace, and 4 MB of memory. `vendor/` isn't traced by default, so
framework calls cost nothing extra. On Windows (Herd, same machine) a call
costs about 1 µs, and everything that touches files is two to six times
slower.

A traced request also runs without opcache, which filo switches off for
it, and in a fresh Laravel app that costs more than filo itself (median of
20 requests to `/` over `php -S`):

| Request | Time |
|---|--:|
| Untraced, opcache on | 11.5 ms |
| Untraced, opcache off | 79.5 ms |
| Traced | 73.9 ms |
| First traced request after emptying filo's cache | 153.2 ms |

The fresh app's traced request records only 4 calls of its own code. It
beats the untraced request without opcache because the files PHP loads
before filo starts, like Composer's autoloader, still come from opcache.

## Public API

From 1.0, semver covers:

- everything in `Filo\Testing` (except members marked `@internal`)
- `Filo\Tracer::cycle()`
- the CLI commands, the env vars and the `.filo-on` marker
- the file formats: `filo.json`, `.filo/breakpoints.json` and the trace
  format above
- the viewer's HTTP API (contract at the top of `server/index.php`)

Every other class and member is marked `@internal` and may change in any
release.

## Known limitations (v1, by design)

- Files preloaded with `opcache.preload` never pass through the
  wrapper, so they aren't traced.
- Files loaded before `vendor/autoload.php` (the front controller) are
  not instrumented. Use `auto_prepend_file` pointing at
  `vendor/giacomomasseron/filo/bootstrap.php` for full coverage.
- Native functions, `eval`'d code and arrow functions show up as
  self-time of their caller.
- Long-running runtimes (Octane, RoadRunner, FrankenPHP workers): call
  `\Filo\Tracer::cycle()` at the end of each request instead of relying
  on the shutdown flush. It does nothing while tracing is off.
- Closures inside anonymous classes are named
  `{closure:class@anonymous::method():12}`; PHP 8.4's own name for them
  also embeds the file path and a compile counter.

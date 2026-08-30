# filo test integration (PHPUnit / Pest) and CI — design

Date: 2026-08-30  ·  Status: approved design, awaiting implementation plan

## Goal

Make filo usable inside test suites and CI pipelines, for three things:

- **A. Performance assertions** — explicit thresholds in the test
  (`toRunUnder(10)`, `toCall('App\Repo::find')->atMost(1)`). No baseline
  files, no state in the repo.
- **B. Debug artifacts** — a trace file per test for tests marked
  `#[Traced]` and for every failing test; CI uploads `.filo/traces/`.
- **C. Live breakpoints** while running a single test locally, using the
  existing Debugger with no new mechanism.

Plus filo's own test suite and a PHP 8.1–8.4 matrix CI, and a documented
consumer recipe.

## Non-goals

- Baselines / regression detection against stored numbers (rejected as
  flaky on CI runners). Counts and thresholds are always explicit.
- PHPUnit 9 or Pest 1 support. Floor is **PHPUnit 10 / Pest 2** (PHP 8.1);
  Pest 3 (PHP ≥ 8.2) resolved by Composer.
- Separate `filo-pest` / `filo-phpunit` packages.
- Memory assertions, nested-capture semantics, a reusable GitHub Action.

## Constraints inherited from the core

- Instrumentation happens at `include` time, so filo must bootstrap before
  the framework autoloads app code. `autoload.files` guarantees this:
  Pest/PHPUnit load `vendor/autoload.php` first.
- Tracing is toggled by the existing global switches only:
  `FILO_ENABLED=1 vendor/bin/pest …` or the `.filo-on` marker.
- `vendor/` is excluded by default, so Pest/PHPUnit themselves are never
  instrumented. Test files under the project are instrumented (that is
  desirable — the test body appears in the trace).
- The hot path (`Collector::enter/leave`, `Debugger::hit`) is untouched.
- Every new `Filo\` class is `require_once`'d in `bootstrap.php` **unless**
  it references PHPUnit/Pest classes; those are autoloaded normally (they
  are only loaded by the framework, after the wrapper is up, and live in
  the self-excluded package dir so the wrapper serves them untouched).

## Section 1 — Core capture

### `Collector` additions (not on the hot path)

```php
public static function mark(): int;            // current $nextId
public static function since(int $mark): array; // events with i >= $mark
```

`since()` returns a self-contained forest: still-open frames (`e === -1`)
are closed at "now"; any `p` that points before `$mark` is rewritten to
`-1`. It does not mutate `$events`.

### `Filo\Testing\Recorder`

```php
public static function capture(Closure $fn): Trace
```

1. `$mark = Collector::mark(); $start = hrtime(true);`
2. run `$fn()`; exceptions propagate (a throwing closure fails the test as
   usual) but the `finally` still slices events and measures wall time.
3. return `new Trace($events, $wallNs, $result)`.

Wall time is measured around the closure itself, so code with zero
instrumented calls still has a duration.

If filo is **not bootstrapped** (`FILO_BOOTSTRAPPED` undefined) `capture`
still runs the closure and returns a `Trace` with wall time and an
`enabled=false` flag. Call-based queries on such a trace throw
`Filo\Testing\FiloNotEnabledException` whose message contains the fix
(`FILO_ENABLED=1 vendor/bin/pest …`). A silent "0 calls" pass is never
possible.

### `Filo\Testing\Trace` (immutable)

| Method | Meaning |
|---|---|
| `wallMs(): float` | closure wall time |
| `calls(string $fn): int` | number of events whose `fn` matches |
| `inclusiveMs(string $fn): float` | Σ (e − s) over matching events |
| `selfMs(string $fn): float` | Σ self time over matching events |
| `functions(): string[]` | distinct names (for messages) |
| `slowestSelf(int $n = 3): array` | `[fn, selfMs, calls]` rows, for failure messages |
| `result(): mixed` | closure return value |
| `enabled(): bool` | false when filo wasn't bootstrapped |
| `toArray(): array` / `toJson(): string` | trace-format v1 (openable in the viewer) |

Name matching: exact match on the `__METHOD__` form filo records
(`App\Repo::find`, `my_function`, `{closure}`), or a trailing `*` glob
(`App\Repo::*`, `App\Repositories\*`). Nothing else.

## Section 2 — Assertions API

### Shared core: `Filo\Testing\Assert`

Static, framework-neutral. Throws `Filo\Testing\ExpectationFailed`
(`RuntimeException`) with messages that name the offender:

- `took 14.2 ms, limit 10 ms (slowest self-time: App\Repo::find 9.8 ms ×11, …)`
- `App\Repo::find called 11 times, expected at most 1`

Methods: `runsUnder(Trace, float $ms)`, `callCount(Trace, string $fn, ?int $atLeast, ?int $atMost)`,
`noCalls(Trace, string $fn)`.

### PHPUnit — `Filo\Testing\FiloAssertions` trait

```php
$trace = $this->capture(fn () => ...);                              // Trace
$this->assertRunsUnder(10, fn () => ...);                           // ms
$this->assertCallCount('App\Repo::find', atMost: 1, callable: fn () => ...);
$this->assertNoCalls('App\Repo::find', fn () => ...);
$this->assertTraceRunsUnder($trace, 10);
$this->assertTraceCallCount($trace, 'App\Repo::find', atMost: 1);
```

`ExpectationFailed` is converted to `Assert::fail()` so it reports as an
assertion failure, not an error.

### Pest — `src/Testing/Pest/Expectations.php`

Registered via the Pest plugin (`composer.json` → `extra.pest.plugins`).

```php
expect(fn () => ...)->toRunUnder(10);                 // returns Expectation, chainable
expect(fn () => ...)->toCall('App\Repo::find')->atMost(1);
expect(fn () => ...)->toCall('App\Repo::find')->atLeast(1)->atMost(3);
expect(fn () => ...)->toCall('App\Repo::find')->times(2);
expect(fn () => ...)->toCallOnce('App\Repo::find');
expect(fn () => ...)->toNotCall('App\Repo::find');    // asserts 0 calls
```

`toCall()` captures once and returns `Filo\Testing\Pest\CallExpectation`
(`atMost`, `atLeast`, `times`). Pest 3 exposes no negation flag to
`extend()`, so negation is a separate expectation. Every method accepts a
ready `Trace` in place of the closure (`expect($trace)->toRunUnder(10)`)
so one capture can back several assertions.

## Section 3 — Artifacts & lifecycle

### `#[Filo\Testing\Traced]`

Marker attribute, no arguments, valid on methods and classes.

### `Filo\Testing\PHPUnit\TraceExtension`

PHPUnit ≥ 10 event-API extension. Pest 2/3 run on PHPUnit 10/11, so the
**same extension serves both** — the Pest plugin registers it; PHPUnit
users add it to `phpunit.xml`:

```xml
<extensions>
  <bootstrap class="Filo\Testing\PHPUnit\TraceExtension"/>
</extensions>
```

Per test:

1. `PreparationStarted` → `$mark = Collector::mark()`, `$start = hrtime(true)`.
2. `Failed` / `Errored` → flag the test as failed.
3. `Finished` → if failed **or** method/class has `#[Traced]`, build a
   `Trace` from `Collector::since($mark)` and write
   `<project>/.filo/traces/tests/<TestClass>__<method>[#<dataset>].json`
   in trace-format v1 with
   `context: {sapi: 'cli', test: 'Class::method', status: 'failed'|'traced'}`.
   Otherwise write nothing.

While the extension is active the per-request shutdown flush is suppressed
(`Tracer::suppressShutdownFlush()`), so a run yields exactly the per-test
files. If filo isn't bootstrapped the extension is a no-op (no warnings).

Test names are sanitised for the filesystem (`\` → `_`, non
`[A-Za-z0-9_.#-]` → `-`).

### Breakpoints in tests

Nothing new. With `FILO_ENABLED=1`, `Debugger` pauses inside the test
process; inspect with `bin/filo pending` / the viewer; `continue`. Pause
time is already excluded via `Collector::excludePause`, so a paused
`toRunUnder` does not fail for inspection time. Caveat documented:
`--process-isolation` / Pest `--parallel` workers inherit env, so several
workers can be paused at once.

### Viewer

`/api/traces` glob widens to `*.json` and `tests/*.json`; `name` carries
the subdirectory (`tests/Foo__bar.json`). `/api/traces/{name}` accepts the
`tests/` prefix. The UI shows `context.test` as the request label (via the
existing `context` handling; no UI change).

## Section 4 — CI/CD

### filo's own suite (`tests/`, run with Pest)

- `tests/Unit/` — `Collector::mark/since` (open frames closed, parents
  rewritten, no mutation), `Trace` queries and glob, `Assert` messages,
  `VarExporter`, `HookVisitor` output snapshots, `Debugger` fail-open and
  timeout (`FILO_BREAK_TIMEOUT=1`).
- `tests/Integration/` — fixtures written to a temp dir at runtime and
  `require`d through the real wrapper (package dir is self-excluded);
  runs with `FILO_ENABLED=1`. Covers `toRunUnder` / `toCall`, the
  `FiloAssertions` trait, `#[Traced]` artifact, failing-test artifact
  (a test that fails on purpose is run via a nested `pest` process and
  the resulting file is asserted), and the `FiloNotEnabledException` path
  (nested `pest` without the env var).
- `tests/Server/` — `php -S` on a random port; asserts the JSON API
  contract: `/api/traces` shape, `/api/breakpoints` normalisation, 403
  without `X-Filo`.

### Workflow `.github/workflows/ci.yml`

Matrix PHP 8.1 · 8.2 · 8.3 · 8.4, plus a `--prefer-lowest` job on 8.1.
Steps: `composer validate`, `php -l` on `src/`, `bootstrap.php`,
`bin/filo`, `server/index.php`; `FILO_ENABLED=1 php -d opcache.enable_cli=0 examples/smoke.php`;
`FILO_ENABLED=1 vendor/bin/pest`; `upload-artifact` of `.filo/traces/`
on failure.

### Consumer recipe (README "Tests & CI")

```yaml
- run: FILO_ENABLED=1 vendor/bin/pest
- uses: actions/upload-artifact@v4
  if: failure()
  with: { name: filo-traces, path: .filo/traces/ }
```

Then: download the artifact, drop it into `.filo/traces/`, run
`vendor/bin/filo serve`.

### Composer

`require-dev`: `pestphp/pest` (`^2 || ^3`), `phpunit/phpunit` (`^10 || ^11`).
`suggest`: both. `extra.pest.plugins`: `Filo\Testing\Pest\Plugin`.
`.gitattributes`: `export-ignore` for `.filo/`, `tests/`, `docs/`,
`.github/`.

## Files

```
src/Collector.php                     +mark(), +since()
src/Tracer.php                        +suppressShutdownFlush()
src/Testing/Recorder.php
src/Testing/Trace.php
src/Testing/Assert.php
src/Testing/ExpectationFailed.php
src/Testing/FiloNotEnabledException.php
src/Testing/Traced.php                attribute
src/Testing/FiloAssertions.php        PHPUnit trait
src/Testing/PHPUnit/TraceExtension.php
src/Testing/Pest/Plugin.php
src/Testing/Pest/Expectations.php
src/Testing/Pest/CallExpectation.php
server/index.php                      tests/ subdir in /api/traces
bootstrap.php                         require_once for the framework-free Testing classes
composer.json, .gitattributes, .github/workflows/ci.yml, tests/**, README.md, CLAUDE.md
```

## Open decisions (all resolved)

- Baselines: none (A1). Attribute + failures for artifacts (B3).
  Existing global toggle for enabling (C1). Own suite + matrix + recipe (D1).
- Single package, `Filo\Testing` namespace (Approach 1).

# Test Integration (PHPUnit / Pest) + CI Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let filo be used inside PHPUnit/Pest suites for explicit performance assertions, per-test trace artifacts, and live breakpoints — and give filo its own test suite + CI matrix.

**Architecture:** A framework-neutral capture core (`Collector::mark/since` → `Recorder::capture` → immutable `Trace` → `Assert`) with two thin adapters: a PHPUnit trait and a Pest expectations file. One PHPUnit ≥10 event extension writes per-test trace files for `#[Traced]` and failing tests; Pest reuses it because Pest runs on PHPUnit.

**Tech Stack:** PHP ^8.1, nikic/php-parser ^5 (existing), pestphp/pest ^2||^3, phpunit/phpunit ^10||^11, GitHub Actions.

**Spec:** `docs/superpowers/specs/2026-08-30-test-integration-design.md`

## Global Constraints

- PHP floor `^8.1`; PHPUnit floor **10**, Pest floor **2** (Pest 3 only on PHP ≥ 8.2, Composer resolves).
- `Collector::enter()`, `Collector::leave()`, `Debugger::hit()` are the hot path — **do not touch them**.
- Every new `Filo\` class that does NOT reference PHPUnit/Pest classes gets a `require_once` in `bootstrap.php` (before `Tracer::start()`); classes referencing PHPUnit/Pest are autoloaded normally.
- Test fixtures that must be instrumented are written to a **temp dir at runtime** — the package dir is self-excluded from instrumentation.
- `declare(strict_types=1)`, `final` classes, no DI, allocation-light everywhere near the hot path.
- Traces are only ever written under `<project>/.filo/traces/` (`Tracer::outputDir()`); tests must redirect via `FILO_PROJECT_ROOT` when they write artifacts.
- Function-name matching: exact `__METHOD__` form or trailing `*` glob only.
- All commands below are run from the repo root with `export PATH="/opt/homebrew/bin:$PATH"` (php/composer live there on this machine).
- Every test run that needs instrumentation is prefixed `FILO_ENABLED=1` and uses `-d opcache.enable_cli=0` where PHP is invoked directly.
- Commit message trailer on every commit:
  ```
  Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>
  Claude-Session: https://claude.ai/code/session_01T5gUdgHXbQTTijxwoAf1ip
  ```

---

## File structure

```
src/Collector.php                       MODIFY  +mark(), +since()
src/Tracer.php                          MODIFY  +suppressShutdownFlush(), flag checked in the shutdown closure
src/Testing/Trace.php                   CREATE  immutable trace value object + queries
src/Testing/Recorder.php                CREATE  capture(Closure): Trace
src/Testing/FiloNotEnabledException.php CREATE  thrown by call-based queries when filo is off
src/Testing/ExpectationFailed.php       CREATE  RuntimeException thrown by Assert
src/Testing/Assert.php                  CREATE  runsUnder / callCount / noCalls
src/Testing/Traced.php                  CREATE  #[Traced] attribute
src/Testing/FiloAssertions.php          CREATE  PHPUnit trait (references PHPUnit\Framework\Assert → autoloaded)
src/Testing/PHPUnit/TraceExtension.php  CREATE  PHPUnit ≥10 extension (autoloaded)
src/Testing/PHPUnit/TestArtifact.php    CREATE  builds + writes the per-test trace file (framework-free helper, bootstrapped)
src/Testing/Pest/Plugin.php             CREATE  Pest Bootable plugin: loads Expectations.php
src/Testing/Pest/Expectations.php       CREATE  expect()->extend(...) registrations
src/Testing/Pest/CallExpectation.php    CREATE  atMost/atLeast/times
server/index.php                        MODIFY  /api/traces + /api/traces/{name} accept tests/ subdir
bootstrap.php                           MODIFY  require_once for Trace, Recorder, both exceptions, Assert, Traced, TestArtifact
composer.json                           MODIFY  require-dev, suggest, extra.pest.plugins, autoload-dev
phpunit.xml                             CREATE  suites, excluded 'fixture' group, extension bootstrap
tests/Pest.php                          CREATE  Pest bootstrap (uses TestCase per dir)
tests/Support/TempProject.php           CREATE  helper: temp project dir + fixture writer
tests/Unit/CollectorMarkTest.php        CREATE
tests/Unit/TraceTest.php                CREATE
tests/Unit/RecorderTest.php             CREATE
tests/Unit/AssertTest.php               CREATE
tests/Integration/PhpUnitTraitTest.php  CREATE  (PHPUnit-style class using FiloAssertions)
tests/Integration/PestExpectationsTest.php CREATE
tests/Integration/ArtifactsTest.php     CREATE  runs nested pest, asserts files
tests/Fixtures/FixtureFailingTest.php   CREATE  group 'fixture' — only run by the nested process
tests/Fixtures/FixtureTracedTest.php    CREATE  group 'fixture'
tests/Server/ApiContractTest.php        CREATE  php -S + curl
.github/workflows/ci.yml                CREATE
.gitattributes                          CREATE
README.md                               MODIFY  "Tests & CI" section
CLAUDE.md                               MODIFY  testing notes + status line
```

---

### Task 0: Dev dependencies and a running (empty) Pest suite

**Files:**
- Modify: `composer.json`
- Create: `phpunit.xml`, `tests/Pest.php`, `tests/Unit/SanityTest.php`

**Interfaces:**
- Produces: a working `vendor/bin/pest` invocation; `tests/Pest.php` binds `PHPUnit\Framework\TestCase` to all suites.

- [ ] **Step 1: Add dev deps and Pest plugin declaration to `composer.json`**

Replace the whole file with:

```json
{
    "name": "giacomomasseron/filo",
    "description": "Filo — zero-extension PHP call tracer: userland instrumentation via stream wrapper + AST rewriting",
    "type": "library",
    "license": "MIT",
    "require": {
        "php": "^8.1",
        "nikic/php-parser": "^5.0",
        "ext-tokenizer": "*"
    },
    "require-dev": {
        "pestphp/pest": "^2.0 || ^3.0",
        "phpunit/phpunit": "^10.0 || ^11.0"
    },
    "suggest": {
        "pestphp/pest": "toRunUnder()/toCall() expectations and automatic per-test trace artifacts",
        "phpunit/phpunit": "Filo\\Testing\\FiloAssertions trait and the TraceExtension"
    },
    "autoload": {
        "psr-4": {
            "Filo\\": "src/"
        },
        "files": [
            "bootstrap.php"
        ]
    },
    "autoload-dev": {
        "psr-4": {
            "Filo\\Tests\\": "tests/"
        }
    },
    "extra": {
        "pest": {
            "plugins": [
                "Filo\\Testing\\Pest\\Plugin"
            ]
        }
    },
    "config": {
        "sort-packages": true,
        "allow-plugins": {
            "pestphp/pest-plugin": true
        }
    },
    "bin": [
        "bin/filo"
    ]
}
```

- [ ] **Step 2: Install**

Run: `composer update --no-interaction 2>&1 | tail -5`
Expected: pest and phpunit installed, no errors. (On PHP 8.5 locally Composer will pick Pest 3 / PHPUnit 11.)

- [ ] **Step 3: Create `phpunit.xml`**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true"
         cacheDirectory=".phpunit.cache"
         failOnWarning="true">
    <testsuites>
        <testsuite name="Unit"><directory>tests/Unit</directory></testsuite>
        <testsuite name="Integration"><directory>tests/Integration</directory></testsuite>
        <testsuite name="Server"><directory>tests/Server</directory></testsuite>
        <testsuite name="Fixtures"><directory>tests/Fixtures</directory></testsuite>
    </testsuites>
    <groups>
        <exclude><group>fixture</group></exclude>
    </groups>
</phpunit>
```

(The `<extensions>` block is added in Task 7 once the extension exists.)

- [ ] **Step 4: Create `tests/Pest.php`**

```php
<?php

declare(strict_types=1);

// Pest bootstrap. Every test file under these dirs gets a plain PHPUnit TestCase.
uses(PHPUnit\Framework\TestCase::class)->in('Unit', 'Integration', 'Server', 'Fixtures');
```

- [ ] **Step 5: Create a sanity test `tests/Unit/SanityTest.php`**

```php
<?php

declare(strict_types=1);

test('php-parser and the Filo namespace are loadable', function (): void {
    expect(class_exists(\PhpParser\Parser::class) || interface_exists(\PhpParser\Parser::class))->toBeTrue()
        ->and(class_exists(\Filo\Collector::class))->toBeTrue();
});
```

- [ ] **Step 6: Run the suite**

Run: `vendor/bin/pest`
Expected: `1 passed` (fixture group excluded, other dirs empty — Pest tolerates empty dirs; if it errors on a missing dir, `mkdir -p tests/Integration tests/Server tests/Fixtures` and add a `.gitkeep`).

- [ ] **Step 7: Add cache dir to .gitignore and commit**

```bash
printf '/.phpunit.cache/\n' >> .gitignore
git add composer.json composer.lock phpunit.xml tests/Pest.php tests/Unit/SanityTest.php .gitignore
git commit -m "test: add Pest/PHPUnit dev dependencies and empty suite"
```

---

### Task 1: `Collector::mark()` / `since()` and `Tracer::suppressShutdownFlush()`

**Files:**
- Modify: `src/Collector.php` (add after `excludePause()`, ~line 102)
- Modify: `src/Tracer.php:65-70` (shutdown closure) and add a static flag + method
- Test: `tests/Unit/CollectorMarkTest.php`

**Interfaces:**
- Produces:
  - `Collector::mark(): int`
  - `Collector::since(int $mark): array` — list of event rows `{i,p,fn,file,line,s,e,m}` with `i >= $mark`, open frames closed at now, `p < $mark` rewritten to `-1`; does not mutate collector state.
  - `Tracer::suppressShutdownFlush(): void`

- [ ] **Step 1: Write the failing tests**

`tests/Unit/CollectorMarkTest.php`:

```php
<?php

declare(strict_types=1);

use Filo\Collector;

beforeEach(fn () => Collector::begin());

test('mark returns the next event id', function (): void {
    expect(Collector::mark())->toBe(0);
    $id = Collector::enter('a', '/f.php', 1);
    Collector::leave($id);
    expect(Collector::mark())->toBe(1);
});

test('since returns only events recorded after the mark', function (): void {
    $before = Collector::enter('before', '/f.php', 1);
    Collector::leave($before);

    $mark = Collector::mark();
    $a = Collector::enter('a', '/f.php', 2);
    $b = Collector::enter('b', '/f.php', 3);
    Collector::leave($b);
    Collector::leave($a);

    $events = Collector::since($mark);

    expect($events)->toHaveCount(2)
        ->and(array_column($events, 'fn'))->toBe(['a', 'b'])
        ->and($events[1]['p'])->toBe($a);
});

test('since rewrites parents that point before the mark to -1', function (): void {
    $outer = Collector::enter('outer', '/f.php', 1);
    $mark  = Collector::mark();
    $inner = Collector::enter('inner', '/f.php', 2);
    Collector::leave($inner);

    $events = Collector::since($mark);

    expect($events)->toHaveCount(1)
        ->and($events[0]['fn'])->toBe('inner')
        ->and($events[0]['p'])->toBe(-1);

    Collector::leave($outer);
});

test('since closes still-open frames without mutating the collector', function (): void {
    $mark = Collector::mark();
    $id   = Collector::enter('open', '/f.php', 1);

    $events = Collector::since($mark);

    expect($events[0]['e'])->toBeGreaterThanOrEqual($events[0]['s']);

    // The real frame is still open: leaving it now must still work and set a later end.
    Collector::leave($id);
    $after = Collector::since($mark);
    expect($after[0]['e'])->toBeGreaterThanOrEqual($events[0]['e']);
});

test('since on a mark past the end returns an empty list', function (): void {
    expect(Collector::since(0))->toBe([])
        ->and(Collector::since(10))->toBe([]);
});
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/pest tests/Unit/CollectorMarkTest.php`
Expected: FAIL — `Call to undefined method Filo\Collector::mark()`.

- [ ] **Step 3: Implement in `src/Collector.php`**

Insert after the `excludePause()` method:

```php
    /**
     * Position marker for scoped captures (Filo\Testing\Recorder).
     * Not on the hot path.
     */
    public static function mark(): int
    {
        return self::$nextId;
    }

    /**
     * Events recorded since mark(), as a self-contained forest: frames
     * still open are closed at "now" and parents that predate the mark
     * become roots (-1). The collector itself is not mutated.
     *
     * @return list<array{i:int,p:int,fn:string,file:string,line:int,s:int,e:int,m:int}>
     */
    public static function since(int $mark): array
    {
        if ($mark >= self::$nextId) {
            return [];
        }

        $now = hrtime(true) - self::$t0;
        $out = [];
        for ($i = max(0, $mark); $i < self::$nextId; $i++) {
            if (!isset(self::$events[$i])) {
                continue; // capped
            }
            $row = self::$events[$i];
            if ($row['e'] === -1) {
                $row['e'] = $now;
            }
            if ($row['p'] < $mark) {
                $row['p'] = -1;
            }
            $out[] = $row;
        }

        return $out;
    }
```

- [ ] **Step 4: Add the flush suppression to `src/Tracer.php`**

Add a property next to `$started`:

```php
    private static bool $suppressFlush = false;
```

Replace the shutdown registration in `start()`:

```php
        register_shutdown_function(static function (): void {
            if (!self::$suppressFlush) {
                Collector::flush(self::$outputDir);
            }
        });
```

Add the method after `start()`:

```php
    /**
     * Test runners write one trace per test (Filo\Testing\PHPUnit\TraceExtension)
     * and must not also get a giant process-wide trace at exit.
     */
    public static function suppressShutdownFlush(): void
    {
        self::$suppressFlush = true;
    }
```

- [ ] **Step 5: Run tests + lint**

Run: `vendor/bin/pest tests/Unit/CollectorMarkTest.php && php -l src/Collector.php && php -l src/Tracer.php`
Expected: `5 passed`, no syntax errors.

- [ ] **Step 6: Smoke test still passes**

Run: `FILO_ENABLED=1 php -d opcache.enable_cli=0 examples/smoke.php`
Expected: 3 `PASS` lines.

- [ ] **Step 7: Commit**

```bash
git add src/Collector.php src/Tracer.php tests/Unit/CollectorMarkTest.php
git commit -m "feat(collector): mark()/since() for scoped capture; Tracer::suppressShutdownFlush()"
```

---

### Task 2: `Filo\Testing\Trace` value object

**Files:**
- Create: `src/Testing/Trace.php`, `src/Testing/FiloNotEnabledException.php`
- Modify: `bootstrap.php` (add `require_once` lines before `src/Tracer.php`)
- Test: `tests/Unit/TraceTest.php`

**Interfaces:**
- Produces:
  ```php
  final class Trace {
      /** @param list<array{i:int,p:int,fn:string,file:string,line:int,s:int,e:int,m:int}> $events */
      public function __construct(array $events, int $wallNs, mixed $result = null, bool $enabled = true);
      public function wallMs(): float;
      public function calls(string $fn): int;
      public function inclusiveMs(string $fn): float;
      public function selfMs(string $fn): float;
      /** @return list<string> */ public function functions(): array;
      /** @return list<array{fn:string,selfMs:float,calls:int}> */ public function slowestSelf(int $n = 3): array;
      public function result(): mixed;
      public function enabled(): bool;
      public function events(): array;
      public function toArray(): array;   // trace format v1
      public function toJson(): string;
      public static function matches(string $pattern, string $fn): bool;
  }
  final class FiloNotEnabledException extends \RuntimeException {}
  ```

- [ ] **Step 1: Write the failing tests**

`tests/Unit/TraceTest.php`:

```php
<?php

declare(strict_types=1);

use Filo\Testing\FiloNotEnabledException;
use Filo\Testing\Trace;

function ev(int $i, int $p, string $fn, int $s, int $e): array
{
    return ['i' => $i, 'p' => $p, 'fn' => $fn, 'file' => '/f.php', 'line' => 1, 's' => $s, 'e' => $e, 'm' => 0];
}

// outer 0..10ms, contains a (2..4ms) and a (5..9ms), a contains b (6..7ms)
function sampleTrace(): Trace
{
    return new Trace([
        ev(0, -1, 'App\Svc::outer', 0, 10_000_000),
        ev(1, 0, 'App\Repo::find', 2_000_000, 4_000_000),
        ev(2, 0, 'App\Repo::find', 5_000_000, 9_000_000),
        ev(3, 2, 'App\Support\Money::of', 6_000_000, 7_000_000),
    ], 12_000_000, 'ret');
}

test('wallMs comes from the wall clock, not the events', function (): void {
    expect(sampleTrace()->wallMs())->toBe(12.0);
});

test('calls counts exact matches', function (): void {
    $t = sampleTrace();
    expect($t->calls('App\Repo::find'))->toBe(2)
        ->and($t->calls('App\Svc::outer'))->toBe(1)
        ->and($t->calls('nope'))->toBe(0);
});

test('calls supports a trailing * glob', function (): void {
    $t = sampleTrace();
    expect($t->calls('App\Repo::*'))->toBe(2)
        ->and($t->calls('App\*'))->toBe(4)
        ->and($t->calls('App\Support\*'))->toBe(1);
});

test('inclusiveMs and selfMs sum over matching calls', function (): void {
    $t = sampleTrace();
    expect($t->inclusiveMs('App\Repo::find'))->toBe(6.0)   // 2 + 4
        ->and($t->selfMs('App\Repo::find'))->toBe(5.0)     // 6 - child 1
        ->and($t->selfMs('App\Svc::outer'))->toBe(4.0);    // 10 - (2 + 4)
});

test('functions lists distinct names in first-seen order', function (): void {
    expect(sampleTrace()->functions())->toBe(['App\Svc::outer', 'App\Repo::find', 'App\Support\Money::of']);
});

test('slowestSelf ranks by self time', function (): void {
    expect(sampleTrace()->slowestSelf(2))->toBe([
        ['fn' => 'App\Repo::find', 'selfMs' => 5.0, 'calls' => 2],
        ['fn' => 'App\Svc::outer', 'selfMs' => 4.0, 'calls' => 1],
    ]);
});

test('result returns the closure return value', function (): void {
    expect(sampleTrace()->result())->toBe('ret');
});

test('toArray is trace format v1', function (): void {
    $a = sampleTrace()->toArray();
    expect($a['version'])->toBe(1)
        ->and($a['duration'])->toBe(12_000_000)
        ->and($a['capped'])->toBeFalse()
        ->and($a['context']['sapi'])->toBe('cli')
        ->and($a['events'])->toHaveCount(4)
        ->and(json_decode(sampleTrace()->toJson(), true)['events'][3]['fn'])->toBe('App\Support\Money::of');
});

test('call queries throw when filo was not enabled', function (): void {
    $t = new Trace([], 5_000_000, null, false);
    expect($t->enabled())->toBeFalse()
        ->and($t->wallMs())->toBe(5.0)
        ->and(fn () => $t->calls('x'))->toThrow(FiloNotEnabledException::class, 'FILO_ENABLED=1');
});

test('matches is exact unless the pattern ends with *', function (): void {
    expect(Trace::matches('App\Repo::find', 'App\Repo::find'))->toBeTrue()
        ->and(Trace::matches('App\Repo::fin', 'App\Repo::find'))->toBeFalse()
        ->and(Trace::matches('App\Repo::*', 'App\Repo::find'))->toBeTrue()
        ->and(Trace::matches('App\Repo*', 'App\Repository::x'))->toBeTrue()
        ->and(Trace::matches('*', 'anything'))->toBeTrue();
});
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/pest tests/Unit/TraceTest.php`
Expected: FAIL — class `Filo\Testing\Trace` not found.

- [ ] **Step 3: Create `src/Testing/FiloNotEnabledException.php`**

```php
<?php

declare(strict_types=1);

namespace Filo\Testing;

/**
 * Thrown by call-based Trace queries when filo was not bootstrapped:
 * a silent "0 calls" pass must never happen.
 */
final class FiloNotEnabledException extends \RuntimeException
{
    public static function create(): self
    {
        return new self(
            'filo is not enabled in this process, so no calls were recorded. '
            . 'Run the suite with FILO_ENABLED=1 (e.g. `FILO_ENABLED=1 vendor/bin/pest`) '
            . 'or create a .filo-on marker file in the project root.',
        );
    }
}
```

- [ ] **Step 4: Create `src/Testing/Trace.php`**

```php
<?php

declare(strict_types=1);

namespace Filo\Testing;

/**
 * Immutable, in-memory trace of one captured closure. Same event rows as
 * the on-disk trace format (README "Trace format"), plus query helpers.
 */
final class Trace
{
    /** @param list<array{i:int,p:int,fn:string,file:string,line:int,s:int,e:int,m:int}> $events */
    public function __construct(
        private readonly array $events,
        private readonly int $wallNs,
        private readonly mixed $result = null,
        private readonly bool $enabled = true,
    ) {
    }

    public function wallMs(): float
    {
        return $this->wallNs / 1e6;
    }

    public function result(): mixed
    {
        return $this->result;
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    /** @return list<array{i:int,p:int,fn:string,file:string,line:int,s:int,e:int,m:int}> */
    public function events(): array
    {
        return $this->events;
    }

    public function calls(string $fn): int
    {
        $this->assertEnabled();
        $n = 0;
        foreach ($this->events as $e) {
            if (self::matches($fn, $e['fn'])) {
                $n++;
            }
        }

        return $n;
    }

    public function inclusiveMs(string $fn): float
    {
        $this->assertEnabled();
        $ns = 0;
        foreach ($this->events as $e) {
            if (self::matches($fn, $e['fn'])) {
                $ns += $e['e'] - $e['s'];
            }
        }

        return $ns / 1e6;
    }

    public function selfMs(string $fn): float
    {
        $this->assertEnabled();
        $self = $this->selfNsById();
        $ns   = 0;
        foreach ($this->events as $e) {
            if (self::matches($fn, $e['fn'])) {
                $ns += $self[$e['i']];
            }
        }

        return $ns / 1e6;
    }

    /** @return list<string> distinct names, first-seen order */
    public function functions(): array
    {
        $seen = [];
        foreach ($this->events as $e) {
            $seen[$e['fn']] = true;
        }

        return array_keys($seen);
    }

    /** @return list<array{fn:string,selfMs:float,calls:int}> */
    public function slowestSelf(int $n = 3): array
    {
        $self = $this->selfNsById();
        $agg  = [];
        foreach ($this->events as $e) {
            $agg[$e['fn']] ??= ['fn' => $e['fn'], 'selfNs' => 0, 'calls' => 0];
            $agg[$e['fn']]['selfNs'] += $self[$e['i']];
            $agg[$e['fn']]['calls']++;
        }
        usort($agg, static fn (array $a, array $b): int => $b['selfNs'] <=> $a['selfNs']);

        return array_map(
            static fn (array $r): array => ['fn' => $r['fn'], 'selfMs' => $r['selfNs'] / 1e6, 'calls' => $r['calls']],
            array_slice($agg, 0, $n),
        );
    }

    /** Trace format v1 — openable in the viewer. */
    public function toArray(): array
    {
        return [
            'version'  => 1,
            'ts'       => date('c'),
            'duration' => $this->wallNs,
            'capped'   => false,
            'context'  => ['sapi' => 'cli', 'capture' => true],
            'events'   => $this->events,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }

    /** Exact match, or prefix match when the pattern ends with '*'. */
    public static function matches(string $pattern, string $fn): bool
    {
        if ($pattern === '*') {
            return true;
        }
        if (str_ends_with($pattern, '*')) {
            return str_starts_with($fn, substr($pattern, 0, -1));
        }

        return $pattern === $fn;
    }

    /** @return array<int, int> event id => self ns */
    private function selfNsById(): array
    {
        $self = [];
        foreach ($this->events as $e) {
            $self[$e['i']] = $e['e'] - $e['s'];
        }
        foreach ($this->events as $e) {
            if ($e['p'] >= 0 && isset($self[$e['p']])) {
                $self[$e['p']] -= $e['e'] - $e['s'];
            }
        }

        return $self;
    }

    private function assertEnabled(): void
    {
        if (!$this->enabled) {
            throw FiloNotEnabledException::create();
        }
    }
}
```

- [ ] **Step 5: Register in `bootstrap.php`**

Insert before `require_once __DIR__ . '/src/Tracer.php';`:

```php
require_once __DIR__ . '/src/Testing/FiloNotEnabledException.php';
require_once __DIR__ . '/src/Testing/Trace.php';
```

- [ ] **Step 6: Run tests + lint**

Run: `vendor/bin/pest tests/Unit/TraceTest.php && php -l src/Testing/Trace.php && php -l bootstrap.php`
Expected: `10 passed`.

- [ ] **Step 7: Commit**

```bash
git add src/Testing/Trace.php src/Testing/FiloNotEnabledException.php bootstrap.php tests/Unit/TraceTest.php
git commit -m "feat(testing): Trace value object with call/time queries"
```

---

### Task 3: `Recorder::capture()`

**Files:**
- Create: `src/Testing/Recorder.php`, `tests/Support/TempProject.php`
- Modify: `bootstrap.php`
- Test: `tests/Unit/RecorderTest.php`

**Interfaces:**
- Consumes: `Collector::mark/since`, `Trace`.
- Produces: `Recorder::capture(\Closure $fn): Trace`; `Recorder::enabled(): bool`.
- Produces test helper `Filo\Tests\Support\TempProject::fixture(string $name, string $php): string` (writes a PHP file to a temp dir and returns its path — required through the real wrapper when filo is enabled).

- [ ] **Step 1: Create the test helper `tests/Support/TempProject.php`**

```php
<?php

declare(strict_types=1);

namespace Filo\Tests\Support;

/**
 * Fixtures must live OUTSIDE the package dir (which filo never
 * instruments), so they are written to a temp dir at runtime.
 */
final class TempProject
{
    private static ?string $dir = null;

    public static function dir(): string
    {
        if (self::$dir === null) {
            self::$dir = sys_get_temp_dir() . '/filo-tests-' . getmypid();
            @mkdir(self::$dir, 0777, true);
        }

        return self::$dir;
    }

    /** Writes `$php` (full file content incl. `<?php`) and returns the path. */
    public static function fixture(string $name, string $php): string
    {
        $path = self::dir() . '/' . $name;
        file_put_contents($path, $php);

        return $path;
    }

    public static function cleanup(): void
    {
        if (self::$dir !== null && is_dir(self::$dir)) {
            foreach (glob(self::$dir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir(self::$dir);
        }
    }
}
```

- [ ] **Step 2: Write the failing tests**

`tests/Unit/RecorderTest.php`:

```php
<?php

declare(strict_types=1);

use Filo\Collector;
use Filo\Testing\Recorder;
use Filo\Testing\Trace;
use Filo\Tests\Support\TempProject;

test('capture returns a Trace with wall time and the closure result', function (): void {
    $t = Recorder::capture(function (): string {
        usleep(5_000);

        return 'done';
    });

    expect($t)->toBeInstanceOf(Trace::class)
        ->and($t->result())->toBe('done')
        ->and($t->wallMs())->toBeGreaterThan(4.0);
});

test('capture slices only the events of the closure', function (): void {
    if (!Recorder::enabled()) {
        $this->markTestSkipped('needs FILO_ENABLED=1');
    }
    $noise = Collector::enter('noise', '/n.php', 1);
    Collector::leave($noise);

    $t = Recorder::capture(function (): void {
        $a = Collector::enter('inside', '/n.php', 2);
        Collector::leave($a);
    });

    expect($t->calls('inside'))->toBe(1)
        ->and($t->calls('noise'))->toBe(0);
});

test('capture instruments real code included through the wrapper', function (): void {
    if (!Recorder::enabled()) {
        $this->markTestSkipped('needs FILO_ENABLED=1');
    }
    require TempProject::fixture('rec_fixture.php', <<<'PHP'
<?php
function filo_rec_leaf(): int { return 1; }
function filo_rec_root(): int { return filo_rec_leaf() + filo_rec_leaf(); }
PHP);

    $t = Recorder::capture(fn (): int => filo_rec_root());

    expect($t->result())->toBe(2)
        ->and($t->calls('filo_rec_root'))->toBe(1)
        ->and($t->calls('filo_rec_leaf'))->toBe(2);
});

test('a throwing closure propagates but still measures', function (): void {
    expect(fn () => Recorder::capture(function (): void {
        throw new LogicException('boom');
    }))->toThrow(LogicException::class, 'boom');
});

test('enabled mirrors FILO_BOOTSTRAPPED', function (): void {
    expect(Recorder::enabled())->toBe(defined('FILO_BOOTSTRAPPED'));
});
```

- [ ] **Step 3: Run to verify failure**

Run: `FILO_ENABLED=1 vendor/bin/pest tests/Unit/RecorderTest.php`
Expected: FAIL — class `Filo\Testing\Recorder` not found.

- [ ] **Step 4: Create `src/Testing/Recorder.php`**

```php
<?php

declare(strict_types=1);

namespace Filo\Testing;

use Closure;
use Filo\Collector;

/**
 * Scoped capture: run a closure, return the events it produced as a Trace.
 * Works whether or not filo is bootstrapped — when it isn't, the Trace
 * carries wall time only and call queries throw FiloNotEnabledException.
 */
final class Recorder
{
    public static function enabled(): bool
    {
        return \defined('FILO_BOOTSTRAPPED');
    }

    public static function capture(Closure $fn): Trace
    {
        $enabled = self::enabled();
        $mark    = $enabled ? Collector::mark() : 0;
        $start   = hrtime(true);
        $events  = [];
        $result  = null;

        try {
            $result = $fn();
        } finally {
            $wall = hrtime(true) - $start;
            if ($enabled) {
                $events = Collector::since($mark);
            }
        }

        return new Trace($events, $wall, $result, $enabled);
    }
}
```

- [ ] **Step 5: Register in `bootstrap.php`** (after the `Trace.php` line)

```php
require_once __DIR__ . '/src/Testing/Recorder.php';
```

- [ ] **Step 6: Run with and without filo**

Run: `FILO_ENABLED=1 vendor/bin/pest tests/Unit/RecorderTest.php`
Expected: `5 passed`.
Run: `vendor/bin/pest tests/Unit/RecorderTest.php`
Expected: `3 passed, 2 skipped`.

- [ ] **Step 7: Commit**

```bash
git add src/Testing/Recorder.php bootstrap.php tests/Support/TempProject.php tests/Unit/RecorderTest.php
git commit -m "feat(testing): Recorder::capture() scoped trace capture"
```

---

### Task 4: `Assert` + `ExpectationFailed`

**Files:**
- Create: `src/Testing/Assert.php`, `src/Testing/ExpectationFailed.php`
- Modify: `bootstrap.php`
- Test: `tests/Unit/AssertTest.php`

**Interfaces:**
- Produces:
  ```php
  final class Assert {
      public static function runsUnder(Trace $t, float $ms): void;
      public static function callCount(Trace $t, string $fn, ?int $atLeast = null, ?int $atMost = null): void;
      public static function noCalls(Trace $t, string $fn): void;
  }
  final class ExpectationFailed extends \RuntimeException {}
  ```

- [ ] **Step 1: Write the failing tests**

`tests/Unit/AssertTest.php`:

```php
<?php

declare(strict_types=1);

use Filo\Testing\Assert;
use Filo\Testing\ExpectationFailed;
use Filo\Testing\Trace;

function assertTrace(): Trace
{
    $ev = fn (int $i, int $p, string $fn, int $s, int $e): array =>
        ['i' => $i, 'p' => $p, 'fn' => $fn, 'file' => '/f.php', 'line' => 1, 's' => $s, 'e' => $e, 'm' => 0];

    return new Trace([
        $ev(0, -1, 'App\Svc::list', 0, 14_000_000),
        $ev(1, 0, 'App\Repo::find', 1_000_000, 4_000_000),
        $ev(2, 0, 'App\Repo::find', 5_000_000, 8_000_000),
        $ev(3, 0, 'App\Repo::find', 9_000_000, 12_800_000),
    ], 14_200_000);
}

test('runsUnder passes under the limit', function (): void {
    Assert::runsUnder(assertTrace(), 20);
    expect(true)->toBeTrue();
});

test('runsUnder fails with a message naming the slowest self-time', function (): void {
    expect(fn () => Assert::runsUnder(assertTrace(), 10))
        ->toThrow(ExpectationFailed::class, 'took 14.2 ms, limit 10 ms (slowest self-time: App\Repo::find 9.8 ms ×3');
});

test('callCount enforces atMost', function (): void {
    Assert::callCount(assertTrace(), 'App\Repo::find', atMost: 3);
    expect(fn () => Assert::callCount(assertTrace(), 'App\Repo::find', atMost: 1))
        ->toThrow(ExpectationFailed::class, 'App\Repo::find called 3 times, expected at most 1');
});

test('callCount enforces atLeast and exact', function (): void {
    Assert::callCount(assertTrace(), 'App\Repo::find', atLeast: 3, atMost: 3);
    expect(fn () => Assert::callCount(assertTrace(), 'App\Repo::find', atLeast: 4))
        ->toThrow(ExpectationFailed::class, 'App\Repo::find called 3 times, expected at least 4');
    expect(fn () => Assert::callCount(assertTrace(), 'App\Repo::find', atLeast: 2, atMost: 2))
        ->toThrow(ExpectationFailed::class, 'App\Repo::find called 3 times, expected exactly 2');
});

test('noCalls', function (): void {
    Assert::noCalls(assertTrace(), 'App\Mail::*');
    expect(fn () => Assert::noCalls(assertTrace(), 'App\Repo::find'))
        ->toThrow(ExpectationFailed::class, 'App\Repo::find called 3 times, expected no calls');
});

test('callCount on a disabled trace surfaces FiloNotEnabledException', function (): void {
    expect(fn () => Assert::callCount(new Trace([], 1, null, false), 'x', atMost: 1))
        ->toThrow(Filo\Testing\FiloNotEnabledException::class);
});
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/pest tests/Unit/AssertTest.php`
Expected: FAIL — class `Filo\Testing\Assert` not found.

- [ ] **Step 3: Create `src/Testing/ExpectationFailed.php`**

```php
<?php

declare(strict_types=1);

namespace Filo\Testing;

/** Framework-neutral assertion failure; adapters convert it to their own failure type. */
final class ExpectationFailed extends \RuntimeException
{
}
```

- [ ] **Step 4: Create `src/Testing/Assert.php`**

```php
<?php

declare(strict_types=1);

namespace Filo\Testing;

/**
 * Shared assertion core for the PHPUnit trait and the Pest expectations.
 * Messages always name the offender so a red build tells you where to look.
 */
final class Assert
{
    public static function runsUnder(Trace $t, float $ms): void
    {
        $wall = $t->wallMs();
        if ($wall < $ms) {
            return;
        }

        $tail = '';
        if ($t->enabled()) {
            $parts = [];
            foreach ($t->slowestSelf(3) as $row) {
                $parts[] = sprintf('%s %s ms ×%d', $row['fn'], self::fmt($row['selfMs']), $row['calls']);
            }
            if ($parts !== []) {
                $tail = ' (slowest self-time: ' . implode(', ', $parts) . ')';
            }
        }

        throw new ExpectationFailed(sprintf('took %s ms, limit %s ms%s', self::fmt($wall), self::fmt($ms), $tail));
    }

    public static function callCount(Trace $t, string $fn, ?int $atLeast = null, ?int $atMost = null): void
    {
        $n = $t->calls($fn);

        if ($atLeast !== null && $atMost !== null && $atLeast === $atMost && $n !== $atLeast) {
            throw new ExpectationFailed(sprintf('%s called %d times, expected exactly %d', $fn, $n, $atLeast));
        }
        if ($atLeast !== null && $n < $atLeast) {
            throw new ExpectationFailed(sprintf('%s called %d times, expected at least %d', $fn, $n, $atLeast));
        }
        if ($atMost !== null && $n > $atMost) {
            throw new ExpectationFailed(sprintf('%s called %d times, expected at most %d', $fn, $n, $atMost));
        }
    }

    public static function noCalls(Trace $t, string $fn): void
    {
        $n = $t->calls($fn);
        if ($n > 0) {
            throw new ExpectationFailed(sprintf('%s called %d times, expected no calls', $fn, $n));
        }
    }

    /** 14.2, 10, 9.8 — one decimal, trailing .0 dropped. */
    private static function fmt(float $ms): string
    {
        return rtrim(rtrim(number_format($ms, 1, '.', ''), '0'), '.');
    }
}
```

- [ ] **Step 5: Register in `bootstrap.php`** (after `Recorder.php`)

```php
require_once __DIR__ . '/src/Testing/ExpectationFailed.php';
require_once __DIR__ . '/src/Testing/Assert.php';
```

- [ ] **Step 6: Run tests**

Run: `vendor/bin/pest tests/Unit/AssertTest.php`
Expected: `6 passed`.

- [ ] **Step 7: Commit**

```bash
git add src/Testing/Assert.php src/Testing/ExpectationFailed.php bootstrap.php tests/Unit/AssertTest.php
git commit -m "feat(testing): framework-neutral Assert with offender-naming messages"
```

---

### Task 5: PHPUnit trait `FiloAssertions`

**Files:**
- Create: `src/Testing/FiloAssertions.php`
- Test: `tests/Integration/PhpUnitTraitTest.php` (a classic PHPUnit class — proves the trait works without Pest sugar)

**Interfaces:**
- Consumes: `Recorder`, `Assert`, `Trace`.
- Produces (trait, `use` in any `PHPUnit\Framework\TestCase`):
  ```php
  protected function capture(\Closure $fn): Trace;
  protected function assertRunsUnder(float $ms, \Closure $fn): Trace;
  protected function assertCallCount(string $fn, ?int $atLeast = null, ?int $atMost = null, ?\Closure $callable = null, ?Trace $trace = null): Trace;
  protected function assertNoCalls(string $fn, \Closure $callable): Trace;
  protected function assertTraceRunsUnder(Trace $trace, float $ms): void;
  protected function assertTraceCallCount(Trace $trace, string $fn, ?int $atLeast = null, ?int $atMost = null): void;
  ```

- [ ] **Step 1: Write the failing test**

`tests/Integration/PhpUnitTraitTest.php`:

```php
<?php

declare(strict_types=1);

namespace Filo\Tests\Integration;

use Filo\Testing\FiloAssertions;
use Filo\Testing\Recorder;
use Filo\Tests\Support\TempProject;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

final class PhpUnitTraitTest extends TestCase
{
    use FiloAssertions;

    protected function setUp(): void
    {
        if (!Recorder::enabled()) {
            self::markTestSkipped('needs FILO_ENABLED=1');
        }
        if (!\function_exists('filo_trait_repo_find')) {
            require TempProject::fixture('trait_fixture.php', <<<'PHP'
<?php
function filo_trait_repo_find(int $id): int { return $id; }
function filo_trait_list(int $n): array {
    $out = [];
    for ($i = 0; $i < $n; $i++) { $out[] = filo_trait_repo_find($i); }
    return $out;
}
PHP);
        }
    }

    public function testCaptureAndCallCount(): void
    {
        $trace = $this->capture(fn (): array => filo_trait_list(3));

        self::assertSame([0, 1, 2], $trace->result());
        $this->assertTraceCallCount($trace, 'filo_trait_repo_find', atMost: 3);
        $this->assertTraceRunsUnder($trace, 500);
    }

    public function testAssertRunsUnderReturnsTheTrace(): void
    {
        $trace = $this->assertRunsUnder(500, fn (): array => filo_trait_list(1));
        self::assertSame(1, $trace->calls('filo_trait_list'));
    }

    public function testFailuresAreAssertionFailures(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('filo_trait_repo_find called 3 times, expected at most 1');

        $this->assertCallCount('filo_trait_repo_find', atMost: 1, callable: fn (): array => filo_trait_list(3));
    }

    public function testAssertNoCalls(): void
    {
        $this->assertNoCalls('filo_trait_repo_find', fn (): array => filo_trait_list(0));

        $this->expectException(AssertionFailedError::class);
        $this->assertNoCalls('filo_trait_repo_find', fn (): array => filo_trait_list(1));
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `FILO_ENABLED=1 vendor/bin/pest tests/Integration/PhpUnitTraitTest.php`
Expected: FAIL — trait `Filo\Testing\FiloAssertions` not found.

- [ ] **Step 3: Create `src/Testing/FiloAssertions.php`**

```php
<?php

declare(strict_types=1);

namespace Filo\Testing;

use Closure;
use PHPUnit\Framework\Assert as PHPUnit;

/**
 * PHPUnit adapter. `use` it in a TestCase. Every method returns the Trace
 * it captured so you can chain further checks on it.
 */
trait FiloAssertions
{
    protected function capture(Closure $fn): Trace
    {
        return Recorder::capture($fn);
    }

    protected function assertRunsUnder(float $ms, Closure $fn): Trace
    {
        $trace = Recorder::capture($fn);
        $this->assertTraceRunsUnder($trace, $ms);

        return $trace;
    }

    protected function assertCallCount(
        string $fn,
        ?int $atLeast = null,
        ?int $atMost = null,
        ?Closure $callable = null,
        ?Trace $trace = null,
    ): Trace {
        if ($trace === null) {
            if ($callable === null) {
                throw new \InvalidArgumentException('assertCallCount needs either $callable or $trace');
            }
            $trace = Recorder::capture($callable);
        }
        $this->assertTraceCallCount($trace, $fn, $atLeast, $atMost);

        return $trace;
    }

    protected function assertNoCalls(string $fn, Closure $callable): Trace
    {
        $trace = Recorder::capture($callable);
        $this->filoRun(static fn () => Assert::noCalls($trace, $fn));

        return $trace;
    }

    protected function assertTraceRunsUnder(Trace $trace, float $ms): void
    {
        $this->filoRun(static fn () => Assert::runsUnder($trace, $ms));
    }

    protected function assertTraceCallCount(Trace $trace, string $fn, ?int $atLeast = null, ?int $atMost = null): void
    {
        $this->filoRun(static fn () => Assert::callCount($trace, $fn, $atLeast, $atMost));
    }

    /** Converts ExpectationFailed into a normal PHPUnit assertion failure. */
    private function filoRun(Closure $check): void
    {
        try {
            $check();
        } catch (ExpectationFailed $e) {
            PHPUnit::fail($e->getMessage());
        }
        PHPUnit::assertTrue(true); // count it as an assertion
    }
}
```

- [ ] **Step 4: Run + lint**

Run: `FILO_ENABLED=1 vendor/bin/pest tests/Integration/PhpUnitTraitTest.php && php -l src/Testing/FiloAssertions.php`
Expected: `4 passed`.

- [ ] **Step 5: Confirm the trait is NOT in bootstrap.php** (it references PHPUnit — autoload only). Run: `grep -c FiloAssertions bootstrap.php` → `0`.

- [ ] **Step 6: Commit**

```bash
git add src/Testing/FiloAssertions.php tests/Integration/PhpUnitTraitTest.php
git commit -m "feat(testing): PHPUnit FiloAssertions trait"
```

---

### Task 6: Pest expectations + plugin

**Files:**
- Create: `src/Testing/Pest/Plugin.php`, `src/Testing/Pest/Expectations.php`, `src/Testing/Pest/CallExpectation.php`
- Test: `tests/Integration/PestExpectationsTest.php`

**Interfaces:**
- Consumes: `Recorder`, `Assert`, `Trace`, `ExpectationFailed`.
- Produces on `expect($closureOrTrace)`: `toRunUnder(float $ms)`, `toCall(string $fn): CallExpectation`, `toCallOnce(string $fn)`, `not->toCall(string $fn)`.
- `CallExpectation`: `atMost(int)`, `atLeast(int)`, `times(int)` — each returns `$this`; exposes `trace(): Trace`.

- [ ] **Step 1: Write the failing tests**

`tests/Integration/PestExpectationsTest.php`:

```php
<?php

declare(strict_types=1);

use Filo\Testing\Recorder;
use Filo\Tests\Support\TempProject;
use PHPUnit\Framework\ExpectationFailedException;

beforeEach(function (): void {
    if (!Recorder::enabled()) {
        $this->markTestSkipped('needs FILO_ENABLED=1');
    }
    if (!function_exists('filo_pest_find')) {
        require TempProject::fixture('pest_fixture.php', <<<'PHP'
<?php
function filo_pest_find(int $id): int { usleep(200); return $id; }
function filo_pest_list(int $n): array {
    $out = [];
    for ($i = 0; $i < $n; $i++) { $out[] = filo_pest_find($i); }
    return $out;
}
PHP);
    }
});

test('toRunUnder passes and is chainable', function (): void {
    expect(fn () => filo_pest_list(2))->toRunUnder(500)->and(1)->toBe(1);
});

test('toRunUnder fails with the filo message', function (): void {
    expect(fn () => expect(fn () => usleep(3_000))->toRunUnder(1))
        ->toThrow(ExpectationFailedException::class, 'limit 1 ms');
});

test('toCall atMost / atLeast / times', function (): void {
    expect(fn () => filo_pest_list(3))->toCall('filo_pest_find')->atMost(3)->atLeast(3)->times(3);
});

test('toCall atMost fails with the offender named', function (): void {
    expect(fn () => expect(fn () => filo_pest_list(3))->toCall('filo_pest_find')->atMost(1))
        ->toThrow(ExpectationFailedException::class, 'filo_pest_find called 3 times, expected at most 1');
});

test('toCallOnce', function (): void {
    expect(fn () => filo_pest_list(1))->toCallOnce('filo_pest_find');
    expect(fn () => expect(fn () => filo_pest_list(2))->toCallOnce('filo_pest_find'))
        ->toThrow(ExpectationFailedException::class, 'expected exactly 1');
});

test('not->toCall asserts zero calls', function (): void {
    expect(fn () => filo_pest_list(0))->not->toCall('filo_pest_find');
    expect(fn () => expect(fn () => filo_pest_list(1))->not->toCall('filo_pest_find'))
        ->toThrow(ExpectationFailedException::class, 'expected no calls');
});

test('a ready Trace can be used instead of a closure', function (): void {
    $trace = Recorder::capture(fn () => filo_pest_list(2));
    expect($trace)->toRunUnder(500);
    expect($trace)->toCall('filo_pest_find')->times(2);
});
```

- [ ] **Step 2: Run to verify failure**

Run: `FILO_ENABLED=1 vendor/bin/pest tests/Integration/PestExpectationsTest.php`
Expected: FAIL — `Expectation::toRunUnder()` does not exist (Pest: "Method [toRunUnder] does not exist").

- [ ] **Step 3: Create `src/Testing/Pest/CallExpectation.php`**

```php
<?php

declare(strict_types=1);

namespace Filo\Testing\Pest;

use Filo\Testing\Assert;
use Filo\Testing\ExpectationFailed;
use Filo\Testing\Trace;
use PHPUnit\Framework\ExpectationFailedException;

/** Returned by expect(fn)->toCall('fn'); the closure has already been captured once. */
final class CallExpectation
{
    public function __construct(private readonly Trace $trace, private readonly string $fn)
    {
    }

    public function trace(): Trace
    {
        return $this->trace;
    }

    public function atMost(int $n): self
    {
        return $this->check(fn () => Assert::callCount($this->trace, $this->fn, atMost: $n));
    }

    public function atLeast(int $n): self
    {
        return $this->check(fn () => Assert::callCount($this->trace, $this->fn, atLeast: $n));
    }

    public function times(int $n): self
    {
        return $this->check(fn () => Assert::callCount($this->trace, $this->fn, atLeast: $n, atMost: $n));
    }

    private function check(\Closure $c): self
    {
        try {
            $c();
        } catch (ExpectationFailed $e) {
            throw new ExpectationFailedException($e->getMessage());
        }

        return $this;
    }
}
```

- [ ] **Step 4: Create `src/Testing/Pest/Expectations.php`**

```php
<?php

declare(strict_types=1);

/*
 * Pest custom expectations. Loaded once by Filo\Testing\Pest\Plugin (or
 * require it from your tests/Pest.php if you don't use the plugin).
 *
 *   expect(fn () => ...)->toRunUnder(10);
 *   expect(fn () => ...)->toCall('App\Repo::find')->atMost(1);
 *   expect(fn () => ...)->toCallOnce('App\Repo::find');
 *   expect(fn () => ...)->not->toCall('App\Repo::find');
 *
 * `expect()` may receive a Closure or an already captured Filo\Testing\Trace.
 */

use Filo\Testing\Assert;
use Filo\Testing\ExpectationFailed;
use Filo\Testing\Pest\CallExpectation;
use Filo\Testing\Recorder;
use Filo\Testing\Trace;
use PHPUnit\Framework\ExpectationFailedException;

if (!function_exists('filo_pest_trace_of')) {
    /** @internal */
    function filo_pest_trace_of(mixed $value): Trace
    {
        if ($value instanceof Trace) {
            return $value;
        }
        if ($value instanceof Closure) {
            return Recorder::capture($value);
        }

        throw new InvalidArgumentException('filo expectations need a Closure or a Filo\Testing\Trace, got ' . get_debug_type($value));
    }

    /** @internal */
    function filo_pest_check(Closure $c): void
    {
        try {
            $c();
        } catch (ExpectationFailed $e) {
            throw new ExpectationFailedException($e->getMessage());
        }
    }
}

expect()->extend('toRunUnder', function (float $ms) {
    $trace = filo_pest_trace_of($this->value);
    filo_pest_check(static fn () => Assert::runsUnder($trace, $ms));

    return $this;
});

expect()->extend('toCall', function (string $fn) {
    $trace = filo_pest_trace_of($this->value);

    // Pest's ->not sets a flag on the Expectation; a negated toCall means "zero calls".
    $negated = property_exists($this, 'negated') ? (bool) $this->negated : false;
    if ($negated) {
        filo_pest_check(static fn () => Assert::noCalls($trace, $fn));

        return $this;
    }

    return new CallExpectation($trace, $fn);
});

expect()->extend('toCallOnce', function (string $fn) {
    $trace = filo_pest_trace_of($this->value);
    filo_pest_check(static fn () => Assert::callCount($trace, $fn, atLeast: 1, atMost: 1));

    return $this;
});
```

- [ ] **Step 5: Create `src/Testing/Pest/Plugin.php`**

```php
<?php

declare(strict_types=1);

namespace Filo\Testing\Pest;

use Pest\Contracts\Plugins\Bootable;

/**
 * Registered via composer.json → extra.pest.plugins. Loads the custom
 * expectations once per process. The per-test artifact extension is a
 * PHPUnit extension registered in phpunit.xml (Pest honours it) — see
 * Filo\Testing\PHPUnit\TraceExtension.
 */
final class Plugin implements Bootable
{
    public function boot(): void
    {
        require_once __DIR__ . '/Expectations.php';
    }
}
```

- [ ] **Step 6: Run the tests**

Run: `FILO_ENABLED=1 vendor/bin/pest tests/Integration/PestExpectationsTest.php`
Expected: `7 passed`.

If the plugin is not picked up (all tests fail with "Method [toRunUnder] does not exist"): run `composer dump-autoload` (Pest discovers plugins from `vendor/composer/installed.json`, which only lists *dependencies* — for the root package Pest may not see `extra.pest.plugins`). In that case add to `tests/Pest.php`:

```php
require_once __DIR__ . '/../src/Testing/Pest/Expectations.php';
```

and keep the plugin for consumers (it IS a dependency for them). Note this in CLAUDE.md in Task 9.

If `->not->toCall` fails because the property is named differently in the installed Pest version: run `grep -n "negat\|opposite" vendor/pestphp/pest/src/Expectation.php` and adjust the `property_exists` name (Pest 2 uses `Opposite` via `__get('not')` — if there is no flag at all, instead make `toCall` accept an optional second parameter and register a separate `toNotCall`, then update the test to use `toNotCall`).

- [ ] **Step 7: Lint + commit**

Run: `php -l src/Testing/Pest/Plugin.php && php -l src/Testing/Pest/Expectations.php && php -l src/Testing/Pest/CallExpectation.php`

```bash
git add src/Testing/Pest tests/Integration/PestExpectationsTest.php tests/Pest.php
git commit -m "feat(testing): Pest toRunUnder/toCall/toCallOnce expectations and plugin"
```

---

### Task 7: `#[Traced]`, `TraceExtension`, per-test artifacts

**Files:**
- Create: `src/Testing/Traced.php`, `src/Testing/PHPUnit/TestArtifact.php`, `src/Testing/PHPUnit/TraceExtension.php`
- Create: `tests/Fixtures/FixtureFailingTest.php`, `tests/Fixtures/FixtureTracedTest.php`
- Modify: `phpunit.xml` (extensions block), `bootstrap.php` (TestArtifact, Traced)
- Test: `tests/Integration/ArtifactsTest.php`

**Interfaces:**
- Produces:
  - `#[\Filo\Testing\Traced]` — `#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]`, no args.
  - `TestArtifact::write(string $projectRoot, string $class, string $method, ?string $dataset, string $status, array $events, int $wallNs): string` — returns the written path `<root>/.filo/traces/tests/<sanitised>.json`.
  - `TestArtifact::fileName(string $class, string $method, ?string $dataset): string`.
  - `TraceExtension` — PHPUnit `Extension`.

- [ ] **Step 1: Write the failing unit test for `TestArtifact` naming** (append to `tests/Unit/TraceTest.php` or new file `tests/Unit/TestArtifactTest.php`)

`tests/Unit/TestArtifactTest.php`:

```php
<?php

declare(strict_types=1);

use Filo\Testing\PHPUnit\TestArtifact;

test('fileName sanitises class, method and dataset', function (): void {
    expect(TestArtifact::fileName('App\Tests\FooTest', 'it_works', null))
        ->toBe('App_Tests_FooTest__it_works.json')
        ->and(TestArtifact::fileName('FooTest', 'bar', 'with spaces/slashes'))
        ->toBe('FooTest__bar#with-spaces-slashes.json');
});

test('write produces a v1 trace file under .filo/traces/tests', function (): void {
    $root = sys_get_temp_dir() . '/filo-artifact-' . getmypid();
    @mkdir($root, 0777, true);

    $path = TestArtifact::write($root, 'FooTest', 'bar', null, 'failed', [], 3_000_000);

    expect($path)->toBe($root . '/.filo/traces/tests/FooTest__bar.json')
        ->and(is_file($path))->toBeTrue();
    $t = json_decode((string) file_get_contents($path), true);
    expect($t['version'])->toBe(1)
        ->and($t['duration'])->toBe(3_000_000)
        ->and($t['context'])->toBe(['sapi' => 'cli', 'test' => 'FooTest::bar', 'status' => 'failed'])
        ->and($t['events'])->toBe([]);
});
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/pest tests/Unit/TestArtifactTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Create `src/Testing/Traced.php`**

```php
<?php

declare(strict_types=1);

namespace Filo\Testing;

use Attribute;

/**
 * Mark a test method (or a whole test class) so a trace file is written
 * for it even when it passes: <project>/.filo/traces/tests/<Class>__<method>.json
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final class Traced
{
}
```

- [ ] **Step 4: Create `src/Testing/PHPUnit/TestArtifact.php`**

```php
<?php

declare(strict_types=1);

namespace Filo\Testing\PHPUnit;

/**
 * Builds and writes the per-test trace file. Framework-free on purpose
 * (bootstrapped eagerly); TraceExtension is the only caller.
 */
final class TestArtifact
{
    public static function fileName(string $class, string $method, ?string $dataset): string
    {
        $name = str_replace('\\', '_', $class) . '__' . $method;
        if ($dataset !== null && $dataset !== '') {
            $name .= '#' . $dataset;
        }

        return preg_replace('/[^A-Za-z0-9_.#-]+/', '-', $name) . '.json';
    }

    /**
     * @param list<array{i:int,p:int,fn:string,file:string,line:int,s:int,e:int,m:int}> $events
     * @param 'failed'|'traced' $status
     * @return string written path
     */
    public static function write(
        string $projectRoot,
        string $class,
        string $method,
        ?string $dataset,
        string $status,
        array $events,
        int $wallNs,
    ): string {
        $dir = $projectRoot . '/.filo/traces/tests';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $path  = $dir . '/' . self::fileName($class, $method, $dataset);
        $trace = [
            'version'  => 1,
            'ts'       => date('c'),
            'duration' => $wallNs,
            'capped'   => false,
            'context'  => ['sapi' => 'cli', 'test' => $class . '::' . $method, 'status' => $status],
            'events'   => $events,
        ];
        file_put_contents($path, json_encode($trace, JSON_INVALID_UTF8_SUBSTITUTE));

        return $path;
    }
}
```

- [ ] **Step 5: Register in `bootstrap.php`** (after `Assert.php`)

```php
require_once __DIR__ . '/src/Testing/Traced.php';
require_once __DIR__ . '/src/Testing/PHPUnit/TestArtifact.php';
```

- [ ] **Step 6: Run unit test**

Run: `vendor/bin/pest tests/Unit/TestArtifactTest.php`
Expected: `2 passed`.

- [ ] **Step 7: Create `src/Testing/PHPUnit/TraceExtension.php`**

```php
<?php

declare(strict_types=1);

namespace Filo\Testing\PHPUnit;

use Filo\Collector;
use Filo\Testing\Recorder;
use Filo\Testing\Traced;
use Filo\Tracer;
use PHPUnit\Event\Code\TestMethod;
use PHPUnit\Event\Test\Errored;
use PHPUnit\Event\Test\ErroredSubscriber;
use PHPUnit\Event\Test\Failed;
use PHPUnit\Event\Test\FailedSubscriber;
use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;
use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;
use ReflectionClass;
use ReflectionMethod;

/**
 * Writes <project>/.filo/traces/tests/<Class>__<method>.json for every
 * failing test and every test carrying #[Traced]. PHPUnit >= 10 event API;
 * Pest 2/3 run on PHPUnit 10/11 so the same class serves both.
 *
 * Register in phpunit.xml:
 *   <extensions><bootstrap class="Filo\Testing\PHPUnit\TraceExtension"/></extensions>
 *
 * No-op when filo is not bootstrapped.
 */
final class TraceExtension implements Extension
{
    private int $mark   = 0;
    private int $start  = 0;
    private bool $failed = false;

    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        if (!Recorder::enabled()) {
            return;
        }

        Tracer::suppressShutdownFlush();

        $facade->registerSubscribers(
            new class($this) implements PreparationStartedSubscriber {
                public function __construct(private readonly TraceExtension $ext) {}
                public function notify(PreparationStarted $event): void { $this->ext->onStart(); }
            },
            new class($this) implements FailedSubscriber {
                public function __construct(private readonly TraceExtension $ext) {}
                public function notify(Failed $event): void { $this->ext->onFailed(); }
            },
            new class($this) implements ErroredSubscriber {
                public function __construct(private readonly TraceExtension $ext) {}
                public function notify(Errored $event): void { $this->ext->onFailed(); }
            },
            new class($this) implements FinishedSubscriber {
                public function __construct(private readonly TraceExtension $ext) {}
                public function notify(Finished $event): void { $this->ext->onFinished($event); }
            },
        );
    }

    /** @internal */
    public function onStart(): void
    {
        $this->mark   = Collector::mark();
        $this->start  = hrtime(true);
        $this->failed = false;
    }

    /** @internal */
    public function onFailed(): void
    {
        $this->failed = true;
    }

    /** @internal */
    public function onFinished(Finished $event): void
    {
        $test = $event->test();
        if (!$test instanceof TestMethod) {
            return;
        }

        $traced = $this->failed || self::hasTracedAttribute($test->className(), $test->methodName());
        if (!$traced) {
            return;
        }

        $dataset = $test->testData()->hasDataFromDataProvider()
            ? (string) $test->testData()->dataFromDataProvider()->dataSetName()
            : null;

        TestArtifact::write(
            Tracer::$projectRoot,
            $test->className(),
            $test->methodName(),
            $dataset,
            $this->failed ? 'failed' : 'traced',
            Collector::since($this->mark),
            hrtime(true) - $this->start,
        );
    }

    private static function hasTracedAttribute(string $class, string $method): bool
    {
        if (!class_exists($class)) {
            return false;
        }
        $rc = new ReflectionClass($class);
        if ($rc->getAttributes(Traced::class) !== []) {
            return true;
        }
        if (!$rc->hasMethod($method)) {
            return false;
        }

        return (new ReflectionMethod($class, $method))->getAttributes(Traced::class) !== [];
    }
}
```

Note for Pest: Pest test functions compile to methods on a generated class; `#[Traced]` on a Pest closure is not possible, so Pest users mark tests with `->group('traced')`? **No** — keep scope: document that `#[Traced]` is for class-based tests (PHPUnit style, which Pest also supports), and Pest closure tests get artifacts on failure only. Record this in README (Task 9).

- [ ] **Step 8: Register the extension in `phpunit.xml`**

Add inside `<phpunit>`:

```xml
    <extensions>
        <bootstrap class="Filo\Testing\PHPUnit\TraceExtension"/>
    </extensions>
```

- [ ] **Step 9: Create the fixture tests (group `fixture`, excluded from normal runs)**

`tests/Fixtures/FixtureFailingTest.php`:

```php
<?php

declare(strict_types=1);

test('fixture: deliberately failing test', function (): void {
    expect(1)->toBe(2);
})->group('fixture');
```

`tests/Fixtures/FixtureTracedTest.php`:

```php
<?php

declare(strict_types=1);

namespace Filo\Tests\Fixtures;

use Filo\Testing\Traced;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('fixture')]
final class FixtureTracedTest extends TestCase
{
    #[Traced]
    public function testTracedPasses(): void
    {
        self::assertTrue(true);
    }

    public function testUntracedPasses(): void
    {
        self::assertTrue(true);
    }
}
```

- [ ] **Step 10: Write the integration test that runs the fixtures in a nested process**

`tests/Integration/ArtifactsTest.php`:

```php
<?php

declare(strict_types=1);

/**
 * Runs the 'fixture' group in a child `pest` process with FILO_PROJECT_ROOT
 * pointed at a temp dir, then asserts which artifact files exist there.
 */
function runFixtures(string $root, bool $enabled): array
{
    $env = array_merge(getenv(), [
        'FILO_PROJECT_ROOT' => $root,
        'FILO_ENABLED'      => $enabled ? '1' : '0',
    ]);
    $cmd = [PHP_BINARY, '-d', 'opcache.enable_cli=0', dirname(__DIR__, 2) . '/vendor/bin/pest', '--group', 'fixture', '--no-coverage'];
    $p   = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2), $env);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    $code = proc_close($p);

    return [$code, $out, glob($root . '/.filo/traces/tests/*.json') ?: []];
}

function artifactRoot(): string
{
    $root = sys_get_temp_dir() . '/filo-artifacts-' . getmypid() . '-' . bin2hex(random_bytes(2));
    mkdir($root, 0777, true);
    file_put_contents($root . '/composer.json', '{}'); // looksLikeProject()

    return $root;
}

test('failing and #[Traced] tests produce artifacts; untraced passing tests do not', function (): void {
    $root = artifactRoot();
    [$code, $out, $files] = runFixtures($root, true);

    expect($code)->not->toBe(0, $out); // the fixture group contains a failing test
    $names = array_map('basename', $files);
    sort($names);

    expect($names)->toBe([
        'Filo_Tests_Fixtures_FixtureTracedTest__testTracedPasses.json',
        'P_Tests_Fixtures_FixtureFailingTest__fixture-deliberately-failing-test.json',
    ]);

    $failed = json_decode((string) file_get_contents($root . '/.filo/traces/tests/' . $names[1]), true);
    expect($failed['context']['status'])->toBe('failed');
    $traced = json_decode((string) file_get_contents($root . '/.filo/traces/tests/' . $names[0]), true);
    expect($traced['context']['status'])->toBe('traced');

    // No process-wide trace at exit: only the tests/ subdir exists.
    expect(glob($root . '/.filo/traces/*.json'))->toBe([]);
});

test('without FILO_ENABLED the extension is a no-op', function (): void {
    $root = artifactRoot();
    [, , $files] = runFixtures($root, false);

    expect($files)->toBe([]);
});
```

- [ ] **Step 11: Run**

Run: `FILO_ENABLED=1 vendor/bin/pest tests/Integration/ArtifactsTest.php`
Expected: `2 passed`. The exact Pest-generated class name for closure tests (`P\Tests\Fixtures\FixtureFailingTest`) and method name (`fixture: deliberately failing test` → sanitised) may differ by Pest version: if the first assertion fails, print `$names` and update the expected list to the real names — the *count* (2) and the statuses are the contract, the exact Pest class prefix is not.

If `registerSubscribers` does not exist on `Facade` in the installed PHPUnit: use `$facade->registerSubscriber($x)` once per subscriber.

- [ ] **Step 12: Whole suite still green; lint; commit**

Run: `FILO_ENABLED=1 vendor/bin/pest && php -l src/Testing/PHPUnit/TraceExtension.php`
Expected: all passed (fixture group excluded, so no failing test in the main run).

```bash
git add src/Testing/Traced.php src/Testing/PHPUnit phpunit.xml bootstrap.php tests/Fixtures tests/Unit/TestArtifactTest.php tests/Integration/ArtifactsTest.php
git commit -m "feat(testing): #[Traced] attribute and PHPUnit TraceExtension writing per-test artifacts"
```

---

### Task 8: Viewer serves `tests/` artifacts + API contract tests

**Files:**
- Modify: `server/index.php` (`/api/traces` glob, `/api/traces/{name}` regex)
- Test: `tests/Server/ApiContractTest.php`

**Interfaces:**
- Produces: `/api/traces` includes `tests/*.json` (name = `tests/<file>.json`); `/api/traces/tests/<file>.json` served.

- [ ] **Step 1: Write the failing tests**

`tests/Server/ApiContractTest.php`:

```php
<?php

declare(strict_types=1);

/**
 * Boots server/index.php with php -S on a free port against a temp project
 * root and checks the JSON API contract the designed UI depends on.
 */
final class ApiServer
{
    public static $proc = null;
    public static string $base = '';
    public static string $root = '';

    public static function start(): void
    {
        self::$root = sys_get_temp_dir() . '/filo-api-' . getmypid();
        @mkdir(self::$root . '/.filo/traces/tests', 0777, true);
        file_put_contents(self::$root . '/composer.json', '{}');

        $port = random_int(18000, 19999);
        self::$base = "http://127.0.0.1:$port";
        $env = array_merge(getenv(), ['FILO_PROJECT_ROOT' => self::$root]);
        self::$proc = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:$port", dirname(__DIR__, 2) . '/server/index.php'],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            $env,
        );
        for ($i = 0; $i < 50; $i++) {
            usleep(100_000);
            if (@file_get_contents(self::$base . '/api/breakpoints') !== false) {
                return;
            }
        }
        throw new RuntimeException('server did not start');
    }

    public static function stop(): void
    {
        if (self::$proc) {
            proc_terminate(self::$proc);
            proc_close(self::$proc);
        }
    }

    /** @return array{int, mixed} [status, decoded body] */
    public static function call(string $method, string $path, ?string $body = null, array $headers = []): array
    {
        $ctx = stream_context_create(['http' => [
            'method'        => $method,
            'header'        => implode("\r\n", $headers),
            'content'       => $body,
            'ignore_errors' => true,
        ]]);
        $raw    = (string) file_get_contents(self::$base . $path, false, $ctx);
        $status = (int) substr($http_response_header[0] ?? 'HTTP/1.1 0', 9, 3);

        return [$status, json_decode($raw, true)];
    }
}

beforeAll(fn () => ApiServer::start());
afterAll(fn () => ApiServer::stop());

test('mutating endpoints require X-Filo', function (): void {
    [$status] = ApiServer::call('POST', '/api/breaks/continue-all');
    expect($status)->toBe(403);
    [$status, $body] = ApiServer::call('POST', '/api/breaks/continue-all', null, ['X-Filo: 1']);
    expect($status)->toBe(200)->and($body)->toBe(['ok' => true]);
});

test('breakpoints round-trip in the UI shape', function (): void {
    [$status, $body] = ApiServer::call('GET', '/api/breakpoints');
    expect($status)->toBe(200)->and($body)->toBe([]);

    [, $saved] = ApiServer::call(
        'PUT',
        '/api/breakpoints',
        json_encode([['fn' => 'App\Foo::bar'], 'plain_fn', ['file' => '/a.php', 'line' => 3, 'enabled' => false]]),
        ['X-Filo: 1', 'Content-Type: application/json'],
    );
    expect($saved)->toHaveCount(3)
        ->and($saved[0])->toMatchArray(['fn' => 'App\Foo::bar', 'enabled' => true])
        ->and($saved[0]['id'])->toStartWith('bp_')
        ->and($saved[1]['fn'])->toBe('plain_fn')
        ->and($saved[2])->toMatchArray(['file' => '/a.php', 'line' => 3, 'enabled' => false]);

    [, $again] = ApiServer::call('GET', '/api/breakpoints');
    expect($again)->toBe($saved);
});

test('/api/traces returns full traces newest first, including tests/ artifacts', function (): void {
    $trace = fn (string $ts) => json_encode(['version' => 1, 'ts' => $ts, 'duration' => 1, 'capped' => false, 'context' => ['sapi' => 'cli'], 'events' => []]);
    file_put_contents(ApiServer::$root . '/.filo/traces/20260101-000000-aaaa.json', $trace('old'));
    file_put_contents(ApiServer::$root . '/.filo/traces/20260102-000000-bbbb.json', $trace('new'));
    file_put_contents(ApiServer::$root . '/.filo/traces/tests/FooTest__bar.json', $trace('test'));
    file_put_contents(ApiServer::$root . '/.filo/traces/breaks-not-a-trace.json', '{"nope":true}');

    [$status, $list] = ApiServer::call('GET', '/api/traces');
    expect($status)->toBe(200)
        ->and(array_column($list, 'name'))->toBe(['tests/FooTest__bar.json', '20260102-000000-bbbb.json', '20260101-000000-aaaa.json'])
        ->and($list[1])->toHaveKeys(['version', 'ts', 'duration', 'capped', 'context', 'events', 'name']);

    [$status, $one] = ApiServer::call('GET', '/api/traces/tests/FooTest__bar.json');
    expect($status)->toBe(200)->and($one['ts'])->toBe('test');

    [$status] = ApiServer::call('GET', '/api/traces/../composer.json');
    expect($status)->toBe(404);
});
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/pest tests/Server/ApiContractTest.php`
Expected: first two pass; the traces test FAILS (no `tests/` entry, `/api/traces/tests/...` → 404).

- [ ] **Step 3: Modify `server/index.php`**

Replace the `/api/traces` list block's file discovery:

```php
    // File names start with Ymd-His, so a reverse name sort is newest-first.
    // tests/ artifacts (Class__method.json) sort after digits and so come first.
    $dir   = rtrim($outputDir, '/');
    $files = array_merge(glob($dir . '/*.json') ?: [], glob($dir . '/tests/*.json') ?: []);
    rsort($files, SORT_STRING);

    $out = [];
    foreach (array_slice($files, 0, TRACE_LIST_LIMIT) as $f) {
        $t = json_decode((string) file_get_contents($f), true);
        if (!is_array($t) || !isset($t['events'])) {
            continue;
        }
        $t['name'] = substr($f, strlen($dir) + 1); // "x.json" or "tests/x.json"
        $out[]     = $t;
    }
    $json($out);
```

And the single-trace route regex:

```php
if (preg_match('#^/api/traces/((?:tests/)?[A-Za-z0-9._#-]+\.json)$#', $path, $m) && $method === 'GET') {
```

(`[A-Za-z0-9._#-]` still forbids `/` and `..` outside the fixed `tests/` prefix, so no traversal.)

- [ ] **Step 4: Run + lint**

Run: `vendor/bin/pest tests/Server/ApiContractTest.php && php -l server/index.php`
Expected: `3 passed`.

- [ ] **Step 5: Update the API contract comment** at the top of `server/index.php`: after the `/api/traces` line add `tests/<Class__method>.json artifacts are listed with the "tests/" prefix in name`.

- [ ] **Step 6: Commit**

```bash
git add server/index.php tests/Server/ApiContractTest.php
git commit -m "feat(server): list and serve per-test trace artifacts; API contract tests"
```

---

### Task 9: CI workflow, README, CLAUDE.md, .gitattributes

**Files:**
- Create: `.github/workflows/ci.yml`, `.gitattributes`
- Modify: `README.md`, `CLAUDE.md`

- [ ] **Step 1: Create `.github/workflows/ci.yml`**

```yaml
name: CI

on:
  push:
    branches: [main]
  pull_request:

jobs:
  test:
    name: PHP ${{ matrix.php }}${{ matrix.deps == 'lowest' && ' (lowest deps)' || '' }}
    runs-on: ubuntu-latest
    strategy:
      fail-fast: false
      matrix:
        php: ['8.1', '8.2', '8.3', '8.4']
        deps: [highest]
        include:
          - php: '8.1'
            deps: lowest
    steps:
      - uses: actions/checkout@v4

      - uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          extensions: tokenizer
          ini-values: opcache.enable_cli=0
          coverage: none

      - name: Install dependencies
        run: |
          if [ "${{ matrix.deps }}" = "lowest" ]; then
            composer update --prefer-lowest --prefer-stable --no-interaction --no-progress
          else
            composer update --no-interaction --no-progress
          fi

      - name: Validate composer.json
        run: composer validate --strict

      - name: Lint
        run: |
          for f in src/*.php src/Testing/*.php src/Testing/*/*.php bootstrap.php bin/filo server/index.php; do php -l "$f"; done

      - name: Smoke test
        run: FILO_ENABLED=1 php -d opcache.enable_cli=0 examples/smoke.php

      - name: Test suite (filo enabled)
        run: FILO_ENABLED=1 vendor/bin/pest --colors=always

      - name: Test suite (filo disabled — assertions must fail loudly, extension must no-op)
        run: vendor/bin/pest --colors=always tests/Unit

      - name: Upload traces on failure
        if: failure()
        uses: actions/upload-artifact@v4
        with:
          name: filo-traces-php${{ matrix.php }}-${{ matrix.deps }}
          path: .filo/traces/
          if-no-files-found: ignore
```

- [ ] **Step 2: Create `.gitattributes`**

```
/.filo            export-ignore
/.github          export-ignore
/docs             export-ignore
/tests            export-ignore
/examples         export-ignore
/phpunit.xml      export-ignore
/.gitattributes   export-ignore
/CLAUDE.md        export-ignore
```

- [ ] **Step 3: Add a "Tests & CI" section to `README.md`** (before "## Trace format")

```markdown
## Tests & CI

filo works inside your test suite once the process is enabled
(`FILO_ENABLED=1 vendor/bin/pest`, or the `.filo-on` marker).

### Performance assertions (no baselines — explicit thresholds only)

**Pest**

```php
expect(fn () => $repo->paginateByUser($user))->toRunUnder(10);          // ms
expect(fn () => $service->list())->toCall('App\Repo::find')->atMost(1);  // N+1 guard
expect(fn () => $service->list())->toCallOnce('App\Repo::find');
expect(fn () => $service->list())->not->toCall('App\Mail\*');            // trailing * = prefix glob
```

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
```

- [ ] **Step 4: Update `CLAUDE.md`**

Change the status header to:

```markdown
## Status: verified on PHP 8.5 locally; CI matrix 8.1–8.4 in .github/workflows/ci.yml
```

Under "Architecture invariants" add:

```markdown
- **Testing module** (`src/Testing/`): framework-free classes (Trace,
  Recorder, Assert, exceptions, Traced, PHPUnit/TestArtifact) are eagerly
  required in `bootstrap.php`; classes that reference PHPUnit/Pest
  (FiloAssertions, PHPUnit/TraceExtension, Pest/*) are autoloaded only.
  `Collector::mark()/since()` are the only collector additions — hot path untouched.
- **Own test suite**: `FILO_ENABLED=1 vendor/bin/pest`. Instrumented fixtures
  are written to a temp dir by `tests/Support/TempProject`. The `fixture`
  group is excluded from normal runs and executed by
  `tests/Integration/ArtifactsTest.php` in a child process with
  `FILO_PROJECT_ROOT` set to a temp dir.
```

Remove roadmap item 1 ("Real test suite") from "Roadmap candidates". Under "Verification pass" replace step 3 with `3. FILO_ENABLED=1 vendor/bin/pest — all green` and keep the smoke line as step 4.

- [ ] **Step 5: Validate locally**

Run: `composer validate --strict && FILO_ENABLED=1 vendor/bin/pest && vendor/bin/pest tests/Unit`
Expected: valid; all passed twice (the second run skips the enabled-only tests).

Run: `git archive --format=tar HEAD | tar -t | grep -c "^tests/"` after committing → `0` (export-ignore works).

- [ ] **Step 6: Commit**

```bash
git add .github/workflows/ci.yml .gitattributes README.md CLAUDE.md
git commit -m "ci: PHP 8.1-8.4 matrix; docs: Tests & CI section"
```

---

## Self-review

**Spec coverage**
- §1 Core capture → Tasks 1, 2, 3 (`mark/since`, `Trace` incl. `slowestSelf`, `enabled`, `toArray/toJson`, `Recorder`, `FiloNotEnabledException`). ✔
- §2 Assertions → Task 4 (`Assert`, messages), Task 5 (PHPUnit trait, all six methods), Task 6 (Pest `toRunUnder`, `toCall` + `CallExpectation`, `toCallOnce`, `not->toCall`, Trace-or-Closure). ✔
- §3 Artifacts → Task 7 (`Traced`, `TraceExtension`, file naming/sanitising, `context.test/status`, `suppressShutdownFlush`, no-op when disabled); viewer `tests/` subdir → Task 8. Breakpoints-in-tests need no code; documented in Task 9. ✔
- §4 CI → Task 9 (matrix + lowest job, lint, smoke, pest enabled + unit-only disabled, artifact upload, README recipe, `.gitattributes`, composer `require-dev`/`suggest`/`extra.pest.plugins` in Task 0). Own suite: Unit (Collector, Trace, Recorder, Assert, TestArtifact), Integration (trait, Pest, artifacts), Server. ✔
- Spec listed `VarExporter`, `HookVisitor` snapshot and `Debugger` timeout unit tests; **not included** — they test pre-existing code, are independent of this feature, and would double the plan. Flagged as follow-up (add as separate small tasks after this plan lands).

**Placeholder scan** — none; every code step has full content. Task 6/7/11 contain explicit contingencies with concrete alternative code paths rather than "handle it".

**Type consistency** — `Trace::__construct(array, int, mixed, bool)` used identically in Tasks 2–7; `Assert::callCount(Trace, string, ?int $atLeast, ?int $atMost)` matches Tasks 4–6; `TestArtifact::write(root, class, method, dataset, status, events, wallNs)` matches Task 7's test and extension; `Recorder::enabled()` used in Tasks 3, 5, 6, 7; `Tracer::$projectRoot` is an existing public static.

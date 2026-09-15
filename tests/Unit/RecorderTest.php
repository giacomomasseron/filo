<?php

declare(strict_types=1);

use Filo\Collector;
use Filo\Instrumenter;
use Filo\Testing\Recorder;
use Filo\Testing\Trace;
use Filo\Tests\Support\TempProject;

test('capture returns a Trace with wall time and the closure result', function (): void {
    // Time the sleep itself: on Windows a relative sleep can end up to one
    // clock tick (15.6 ms by default) earlier than asked, as hrtime sees it.
    $sleptNs = 0;
    $t = Recorder::capture(function () use (&$sleptNs): string {
        $start = hrtime(true);
        usleep(5_000);
        $sleptNs = hrtime(true) - $start;

        return 'done';
    });

    expect($t)->toBeInstanceOf(Trace::class)
        ->and($t->result())->toBe('done')
        ->and($t->wallMs())->toBeGreaterThanOrEqual($sleptNs / 1e6); // the wall time covers the closure
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

test('capture excludes paused time from the wall clock', function (): void {
    if (!Recorder::enabled()) {
        $this->markTestSkipped('needs FILO_ENABLED=1');
    }
    // Exclude the pause as measured, like Debugger::pause(): a sleep can
    // last far longer than asked (macOS CI runners oversleep 20 ms by ~80).
    $t = Recorder::capture(function (): void {
        $start = hrtime(true);
        usleep(50_000);
        Collector::excludePause(hrtime(true) - $start);
    });

    expect($t->wallMs())->toBeLessThan(10.0); // only the capture's own overhead is left
});

test('capture excludes first-include instrumentation time from the wall clock', function (): void {
    if (!Recorder::enabled()) {
        $this->markTestSkipped('needs FILO_ENABLED=1');
    }
    // A fresh file name guarantees a cache miss. 2000 functions make
    // php-parser's parse + rewrite dwarf PHP compiling the result.
    $tag  = bin2hex(random_bytes(4));
    $body = '';
    for ($i = 0; $i < 2000; $i++) {
        $body .= "function filo_cold_{$tag}_{$i}(int \$a): int { return \$a + {$i}; }\n";
    }
    $source = "<?php\n" . $body;
    $path   = TempProject::fixture("cold_{$tag}.php", $source);

    $t = Recorder::capture(static fn () => require $path);

    // What instrumenting this file costs, timed directly outside any capture.
    $start = hrtime(true);
    Instrumenter::instrument($source);
    $instrumentMs = (hrtime(true) - $start) / 1e6;

    expect($t->wallMs())->toBeLessThan($instrumentMs / 2);
});

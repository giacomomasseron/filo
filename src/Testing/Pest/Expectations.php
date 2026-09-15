<?php

declare(strict_types=1);

/*
 * Pest custom expectations. Loaded once by Filo\Testing\Pest\Plugin (or
 * require it from your tests/Pest.php if you don't use the plugin).
 *
 *   expect(fn () => ...)->toRunUnder(10);
 *   expect(fn () => ...)->toCall('App\Repo::find')->atMost(1);
 *   expect(fn () => ...)->toCallOnce('App\Repo::find');
 *   expect(fn () => ...)->toNotCall('App\Repo::find');
 *
 * `expect()` may receive a Closure or an already captured Filo\Testing\Trace.
 * A closure is captured (run) only once per chain: every Filo expectation
 * below replaces $this->value with the captured Trace, so a later Filo
 * expectation in the same chain reuses it via filo_pest_trace_of() instead
 * of re-invoking the closure.
 *
 * `toNotCall()` exists instead of `->not->toCall()`: the installed Pest
 * (pestphp/pest v3.8.7) represents `->not` as Pest\Expectations\OppositeExpectation,
 * a wrapper with no flag reachable from inside an extend() closure, and its
 * generic try/throw inversion doesn't fit toCall()'s own fluent-chain design
 * (see the comment on the `toNotCall` extend below).
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

        // Assert:: methods only throw on failure; on success they return
        // void without touching PHPUnit's assertion counter, which makes
        // a test that only chains filo expectations come back "risky:
        // this test did not perform any assertions". Register one
        // explicitly so a clean pass reads as an actual pass.
        \PHPUnit\Framework\Assert::assertTrue(true); // @phpstan-ignore staticMethod.alreadyNarrowedType (counts one assertion on purpose)
    }
}

expect()->extend('toRunUnder', function (float $ms) {
    $trace = filo_pest_trace_of($this->value);
    filo_pest_check(static fn () => Assert::runsUnder($trace, $ms));

    // Memoize: swap $value for the captured Trace so a later Filo
    // expectation chained after this one (e.g. ->toCall(...)) reuses it
    // via filo_pest_trace_of() instead of re-invoking (and re-running the
    // side effects of) the original closure.
    $this->value = $trace;

    return $this;
});

expect()->extend('toCall', function (string $fn) {
    $trace = filo_pest_trace_of($this->value);

    // Pest's expect()->extend() pipeline discards whatever the closure
    // returns and always hands the caller back $this (see
    // Pest\Support\ExpectationPipeline::run(): void). To let ->toCall()
    // still read as `expect(...)->toCall('fn')->atMost(1)`, we swap the
    // expectation's own $value for the CallExpectation: Pest's generic
    // __call() fallback (Expectation::__call(), used whenever a method
    // isn't a known/extended one) then delegates atMost()/atLeast()/
    // times() straight onto that object. This also memoizes the capture
    // (see the module docblock): later Filo expectations chained off the
    // CallExpectation reuse the same Trace via CallExpectation::trace().
    $this->value = new CallExpectation($trace, $fn);

    return $this;
});

expect()->extend('toCallOnce', function (string $fn) {
    $trace = filo_pest_trace_of($this->value);
    filo_pest_check(static fn () => Assert::callCount($trace, $fn, atLeast: 1, atMost: 1));

    // Memoize: see the ->toRunUnder() extend above for why.
    $this->value = $trace;

    return $this;
});

expect()->extend('toNotCall', function (string $fn) {
    // Pest 3's ->not is Pest\Expectations\OppositeExpectation, a separate
    // wrapper with no accessible flag inside an extend() closure (it has
    // no `value`-sibling property at all — see Pest\Expectation, whose
    // only state is the public $value). It also can't be used to negate
    // toCall() here: OppositeExpectation::__call() re-invokes the
    // non-negated method and only distinguishes pass/fail by whether that
    // call throws, but toCall() never throws (it just returns a
    // CallExpectation for chaining) — so ->not->toCall() would always
    // report failure with a generic message, never our own. Hence a
    // dedicated, explicitly-registered expectation instead of ->not->toCall().
    $trace = filo_pest_trace_of($this->value);
    filo_pest_check(static fn () => Assert::noCalls($trace, $fn));

    // Memoize: see the ->toRunUnder() extend above for why.
    $this->value = $trace;

    return $this;
});

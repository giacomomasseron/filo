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

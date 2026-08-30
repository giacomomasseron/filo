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

        // Assert::callCount() only throws on failure; on success it
        // returns void without touching PHPUnit's assertion counter,
        // which makes a test that only chains atMost()/atLeast()/times()
        // come back "risky: this test did not perform any assertions".
        // Register one explicitly so a clean pass reads as an actual pass.
        \PHPUnit\Framework\Assert::assertTrue(true);

        return $this;
    }
}

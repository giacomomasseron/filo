<?php

declare(strict_types=1);

namespace Filo\Testing\Pest;

use Filo\Testing\Assert;
use Filo\Testing\Trace;

/**
 * Returned by expect(fn)->toCall('fn'); the closure has already been captured
 * once. NOTE: toCall() alone asserts nothing — always finish the chain with
 * atMost(), atLeast() or times().
 */
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
        // Shared with the expectations: converts ExpectationFailed and
        // registers one PHPUnit assertion so a clean pass isn't "risky".
        // Expectations.php is always loaded before a CallExpectation can
        // exist (toCall() lives there), so the function is available.
        \filo_pest_check($c);

        return $this;
    }
}

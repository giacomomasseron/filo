<?php

declare(strict_types=1);

namespace Filo\Testing;

use Filo\Testing\PHPUnit\TraceExtension;
use InvalidArgumentException;
use PHPUnit\Framework\Assert as PHPUnit;
use PHPUnit\Framework\Attributes\PostCondition;

/**
 * Fails any test that runs longer than the global threshold set on
 * TraceExtension in phpunit.xml:
 *
 *   <bootstrap class="Filo\Testing\PHPUnit\TraceExtension">
 *     <parameter name="threshold" value="200"/>  <!-- ms -->
 *   </bootstrap>
 *
 * `use` it in your base TestCase, or `uses(EnforcesThreshold::class)->in(...)`
 * in tests/Pest.php. A trait rather than extension logic because PHPUnit
 * extensions can observe tests but never fail them; this hook runs inside
 * the test.
 *
 * Measured from test preparation (before setUp/beforeEach) to the end of
 * the test body: tearDown/afterEach and breakpoint pauses don't count.
 * A no-op unless filo is enabled and TraceExtension is registered.
 */
trait EnforcesThreshold
{
    private bool $filoThresholdSet = false;

    private ?float $filoThreshold = null;

    /** This test's own limit in ms, replacing the global one; null = no limit. */
    protected function threshold(?float $ms): void
    {
        if ($ms !== null && $ms <= 0) {
            throw new InvalidArgumentException(sprintf('threshold must be > 0 ms, or null for no limit; got %s', $ms));
        }

        $this->filoThresholdSet = true;
        $this->filoThreshold    = $ms;
    }

    /**
     * Post-condition hooks run only after a passing test body, so a test
     * that already failed never collects a second failure from here.
     *
     * @internal
     */
    #[PostCondition]
    protected function filoEnforceThreshold(): void
    {
        $limit = $this->filoThresholdSet ? $this->filoThreshold : TraceExtension::threshold();
        $trace = $limit === null ? null : TraceExtension::currentTest();
        if ($trace === null) {
            return;
        }

        try {
            Assert::runsUnder($trace, $limit);
        } catch (ExpectationFailed $e) {
            PHPUnit::fail('filo threshold: ' . $e->getMessage());
        }
    }
}

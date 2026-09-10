<?php

declare(strict_types=1);

namespace Filo\Tests\Fixtures\Threshold;

use Filo\Testing\EnforcesThreshold;
use Filo\Tests\Support\TempProject;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** Class-based twin of PestThresholdFixtureTest.php (see its header). */
#[Group('threshold-fixture')]
final class PhpUnitThresholdFixtureTest extends TestCase
{
    use EnforcesThreshold;

    protected function setUp(): void
    {
        if (!\function_exists('filo_threshold_nap')) {
            require TempProject::fixture('threshold_nap.php', <<<'PHP'
<?php
function filo_threshold_nap(): void { usleep(200_000); }
PHP);
        }
    }

    public function testOverLimit(): void
    {
        filo_threshold_nap();
        self::assertTrue(true);
    }

    public function testUnderLimit(): void
    {
        self::assertTrue(true);
    }

    public function testRaisedLimit(): void
    {
        $this->threshold(1000);
        filo_threshold_nap();
        self::assertTrue(true);
    }

    public function testDisabledLimit(): void
    {
        $this->threshold(null);
        filo_threshold_nap();
        self::assertTrue(true);
    }
}

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

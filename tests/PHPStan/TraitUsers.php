<?php

declare(strict_types=1);

namespace Filo\Tests\PHPStan;

use Filo\Testing\EnforcesThreshold;
use Filo\Testing\FiloAssertions;
use PHPUnit\Framework\TestCase;

/*
 * For PHPStan only (see phpstan.neon): it skips traits that no analysed
 * class uses, and these two are used by filo's users, not by filo. Never
 * autoloaded or run.
 */

final class UsesFiloAssertions extends TestCase
{
    use FiloAssertions;
}

final class UsesEnforcesThreshold extends TestCase
{
    use EnforcesThreshold;
}

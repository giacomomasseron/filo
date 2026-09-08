<?php

declare(strict_types=1);

// Pest bootstrap. Every test file under these dirs gets a plain PHPUnit TestCase.
uses(PHPUnit\Framework\TestCase::class)->in('Unit', 'Integration', 'Server', 'Fixtures');

// Remove every temp root this process created once the run is over.
register_shutdown_function(static fn () => Filo\Tests\Support\TempProject::purgeOwn());

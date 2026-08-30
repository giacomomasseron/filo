<?php

declare(strict_types=1);

// Pest bootstrap. Every test file under these dirs gets a plain PHPUnit TestCase.
uses(PHPUnit\Framework\TestCase::class)->in('Unit', 'Integration', 'Server', 'Fixtures');

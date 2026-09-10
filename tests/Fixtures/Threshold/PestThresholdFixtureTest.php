<?php

declare(strict_types=1);

/*
 * Run by tests/Integration/ThresholdTest.php in a child `pest` process whose
 * TraceExtension sets threshold=100 (ms). Excluded from normal runs.
 * filo_threshold_nap() lives in a temp fixture so filo instruments it.
 */

use Filo\Testing\EnforcesThreshold;
use Filo\Tests\Support\TempProject;

uses(EnforcesThreshold::class);

beforeEach(function (): void {
    if (!function_exists('filo_threshold_nap')) {
        require TempProject::fixture('threshold_nap.php', <<<'PHP'
<?php
function filo_threshold_nap(): void { usleep(200_000); }
PHP);
    }
});

test('threshold fixture: over limit', function (): void {
    filo_threshold_nap();
    expect(true)->toBeTrue();
})->group('threshold-fixture');

test('threshold fixture: under limit', function (): void {
    expect(true)->toBeTrue();
})->group('threshold-fixture');

test('threshold fixture: raised limit', function (): void {
    $this->threshold(1000);
    filo_threshold_nap();
    expect(true)->toBeTrue();
})->group('threshold-fixture');

test('threshold fixture: disabled limit', function (): void {
    $this->threshold(null);
    filo_threshold_nap();
    expect(true)->toBeTrue();
})->group('threshold-fixture');

test('threshold fixture: failing on its own', function (): void {
    filo_threshold_nap(); // over the limit too, but its own failure must win
    expect(1)->toBe(2);
})->group('threshold-fixture');

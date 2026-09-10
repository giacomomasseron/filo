<?php

declare(strict_types=1);

use Filo\Testing\EnforcesThreshold;

uses(EnforcesThreshold::class);

test('threshold rejects a non-positive limit', function (float $ms): void {
    expect(fn () => $this->threshold($ms))
        ->toThrow(InvalidArgumentException::class, 'threshold must be > 0 ms');
})->with([0.0, -5.0]);

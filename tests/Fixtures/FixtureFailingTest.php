<?php

declare(strict_types=1);

test('fixture: deliberately failing test', function (): void {
    expect(1)->toBe(2);
})->group('fixture');

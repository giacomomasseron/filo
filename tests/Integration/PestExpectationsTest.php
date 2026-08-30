<?php

declare(strict_types=1);

use Filo\Testing\Recorder;
use Filo\Tests\Support\TempProject;
use PHPUnit\Framework\ExpectationFailedException;

beforeEach(function (): void {
    if (!Recorder::enabled()) {
        $this->markTestSkipped('needs FILO_ENABLED=1');
    }
    if (!function_exists('filo_pest_find')) {
        require TempProject::fixture('pest_fixture.php', <<<'PHP'
<?php
function filo_pest_find(int $id): int { usleep(200); return $id; }
function filo_pest_list(int $n): array {
    $out = [];
    for ($i = 0; $i < $n; $i++) { $out[] = filo_pest_find($i); }
    return $out;
}
PHP);
    }
});

test('toRunUnder passes and is chainable', function (): void {
    expect(fn () => filo_pest_list(2))->toRunUnder(500)->and(1)->toBe(1);
});

test('toRunUnder fails with the filo message', function (): void {
    expect(fn () => expect(fn () => usleep(3_000))->toRunUnder(1))
        ->toThrow(ExpectationFailedException::class, 'limit 1 ms');
});

test('toCall atMost / atLeast / times', function (): void {
    expect(fn () => filo_pest_list(3))->toCall('filo_pest_find')->atMost(3)->atLeast(3)->times(3);
});

test('toCall atMost fails with the offender named', function (): void {
    expect(fn () => expect(fn () => filo_pest_list(3))->toCall('filo_pest_find')->atMost(1))
        ->toThrow(ExpectationFailedException::class, 'filo_pest_find called 3 times, expected at most 1');
});

test('toCallOnce', function (): void {
    expect(fn () => filo_pest_list(1))->toCallOnce('filo_pest_find');
    expect(fn () => expect(fn () => filo_pest_list(2))->toCallOnce('filo_pest_find'))
        ->toThrow(ExpectationFailedException::class, 'expected exactly 1');
});

test('toNotCall asserts zero calls', function (): void {
    // Fallback per task-6 brief Step 6: the installed Pest (v3.8.7) represents
    // ->not as Pest\Expectations\OppositeExpectation, which has no negation
    // flag reachable inside an expect()->extend() closure and, since toCall()
    // never throws on its own (it returns a CallExpectation for chaining),
    // ->not->toCall() would always fail with a generic Pest message instead
    // of ours. See src/Testing/Pest/Expectations.php for the full rationale.
    expect(fn () => filo_pest_list(0))->toNotCall('filo_pest_find');
    expect(fn () => expect(fn () => filo_pest_list(1))->toNotCall('filo_pest_find'))
        ->toThrow(ExpectationFailedException::class, 'expected no calls');
});

test('chaining expectations on a closure captures it only once', function (): void {
    $calls = 0;
    $fn = function () use (&$calls): void { $calls++; filo_pest_list(1); };

    expect($fn)->toRunUnder(500)->toCall('filo_pest_find')->atLeast(1);
    expect($calls)->toBe(1);
});

test('a ready Trace can be used instead of a closure', function (): void {
    $trace = Recorder::capture(fn () => filo_pest_list(2));
    expect($trace)->toRunUnder(500);
    expect($trace)->toCall('filo_pest_find')->times(2);
});

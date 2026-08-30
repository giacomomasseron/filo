<?php

declare(strict_types=1);

use Filo\Testing\FiloNotEnabledException;
use Filo\Testing\Trace;
use Filo\Testing\TraceCappedException;

function ev(int $i, int $p, string $fn, int $s, int $e): array
{
    return ['i' => $i, 'p' => $p, 'fn' => $fn, 'file' => '/f.php', 'line' => 1, 's' => $s, 'e' => $e, 'm' => 0];
}

// outer 0..10ms, contains a (2..4ms) and a (5..9ms), a contains b (6..7ms)
function sampleTrace(): Trace
{
    return new Trace([
        ev(0, -1, 'App\Svc::outer', 0, 10_000_000),
        ev(1, 0, 'App\Repo::find', 2_000_000, 4_000_000),
        ev(2, 0, 'App\Repo::find', 5_000_000, 9_000_000),
        ev(3, 2, 'App\Support\Money::of', 6_000_000, 7_000_000),
    ], 12_000_000, 'ret');
}

test('wallMs comes from the wall clock, not the events', function (): void {
    expect(sampleTrace()->wallMs())->toBe(12.0);
});

test('calls counts exact matches', function (): void {
    $t = sampleTrace();
    expect($t->calls('App\Repo::find'))->toBe(2)
        ->and($t->calls('App\Svc::outer'))->toBe(1)
        ->and($t->calls('nope'))->toBe(0);
});

test('calls supports a trailing * glob', function (): void {
    $t = sampleTrace();
    expect($t->calls('App\Repo::*'))->toBe(2)
        ->and($t->calls('App\*'))->toBe(4)
        ->and($t->calls('App\Support\*'))->toBe(1);
});

test('inclusiveMs and selfMs sum over matching calls', function (): void {
    $t = sampleTrace();
    expect($t->inclusiveMs('App\Repo::find'))->toBe(6.0)   // 2 + 4
        ->and($t->selfMs('App\Repo::find'))->toBe(5.0)     // 6 - child 1
        ->and($t->selfMs('App\Svc::outer'))->toBe(4.0);    // 10 - (2 + 4)
});

test('functions lists distinct names in first-seen order', function (): void {
    expect(sampleTrace()->functions())->toBe(['App\Svc::outer', 'App\Repo::find', 'App\Support\Money::of']);
});

test('slowestSelf ranks by self time', function (): void {
    expect(sampleTrace()->slowestSelf(2))->toBe([
        ['fn' => 'App\Repo::find', 'selfMs' => 5.0, 'calls' => 2],
        ['fn' => 'App\Svc::outer', 'selfMs' => 4.0, 'calls' => 1],
    ]);
});

test('result returns the closure return value', function (): void {
    expect(sampleTrace()->result())->toBe('ret');
});

test('toArray is trace format v1', function (): void {
    $a = sampleTrace()->toArray();
    expect($a['version'])->toBe(1)
        ->and($a['duration'])->toBe(12_000_000)
        ->and($a['capped'])->toBeFalse()
        ->and($a['context']['sapi'])->toBe(PHP_SAPI)
        ->and($a['events'])->toHaveCount(4)
        ->and(json_decode(sampleTrace()->toJson(), true)['events'][3]['fn'])->toBe('App\Support\Money::of');
});

test('call queries throw when filo was not enabled', function (): void {
    $t = new Trace([], 5_000_000, null, false);
    expect($t->enabled())->toBeFalse()
        ->and($t->wallMs())->toBe(5.0)
        ->and(fn () => $t->calls('x'))->toThrow(FiloNotEnabledException::class, 'FILO_ENABLED=1');
});

test('matches is exact unless the pattern ends with *', function (): void {
    expect(Trace::matches('App\Repo::find', 'App\Repo::find'))->toBeTrue()
        ->and(Trace::matches('App\Repo::fin', 'App\Repo::find'))->toBeFalse()
        ->and(Trace::matches('App\Repo::*', 'App\Repo::find'))->toBeTrue()
        ->and(Trace::matches('App\Repo*', 'App\Repository::x'))->toBeTrue()
        ->and(Trace::matches('*', 'anything'))->toBeTrue();
});

test('call queries throw when the collector was capped', function (): void {
    $t = new Trace([], 5_000_000, null, true, true);
    expect($t->capped())->toBeTrue()
        ->and($t->wallMs())->toBe(5.0)
        ->and($t->toArray()['capped'])->toBeTrue()
        ->and(fn () => $t->calls('x'))->toThrow(TraceCappedException::class, '500000');
});

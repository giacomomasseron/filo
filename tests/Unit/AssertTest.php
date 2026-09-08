<?php

declare(strict_types=1);

use Filo\Testing\Assert;
use Filo\Testing\ExpectationFailed;
use Filo\Testing\Trace;

function assertTrace(): Trace
{
    $ev = fn (int $i, int $p, string $fn, int $s, int $e): array =>
        ['i' => $i, 'p' => $p, 'fn' => $fn, 'file' => '/f.php', 'line' => 1, 's' => $s, 'e' => $e, 'm' => 0];

    return new Trace([
        $ev(0, -1, 'App\Svc::list', 0, 14_000_000),
        $ev(1, 0, 'App\Repo::find', 1_000_000, 4_000_000),
        $ev(2, 0, 'App\Repo::find', 5_000_000, 8_000_000),
        $ev(3, 0, 'App\Repo::find', 9_000_000, 12_800_000),
    ], 14_200_000);
}

test('runsUnder passes under the limit', function (): void {
    Assert::runsUnder(assertTrace(), 20);
    expect(true)->toBeTrue();
});

test('runsUnder fails with a message naming the slowest self-time', function (): void {
    expect(fn () => Assert::runsUnder(assertTrace(), 10))
        ->toThrow(ExpectationFailed::class, 'took 14.2 ms, limit 10 ms (slowest self-time: App\Repo::find 9.8 ms ×3');
});

test('callCount enforces atMost', function (): void {
    Assert::callCount(assertTrace(), 'App\Repo::find', atMost: 3);
    expect(fn () => Assert::callCount(assertTrace(), 'App\Repo::find', atMost: 1))
        ->toThrow(ExpectationFailed::class, 'App\Repo::find called 3 times, expected at most 1');
});

test('callCount enforces atLeast and exact', function (): void {
    Assert::callCount(assertTrace(), 'App\Repo::find', atLeast: 3, atMost: 3);
    expect(fn () => Assert::callCount(assertTrace(), 'App\Repo::find', atLeast: 4))
        ->toThrow(ExpectationFailed::class, 'App\Repo::find called 3 times, expected at least 4');
    expect(fn () => Assert::callCount(assertTrace(), 'App\Repo::find', atLeast: 2, atMost: 2))
        ->toThrow(ExpectationFailed::class, 'App\Repo::find called 3 times, expected exactly 2');
});

test('noCalls', function (): void {
    Assert::noCalls(assertTrace(), 'App\Mail::*');
    expect(fn () => Assert::noCalls(assertTrace(), 'App\Repo::find'))
        ->toThrow(ExpectationFailed::class, 'App\Repo::find called 3 times, expected no calls');
});

test('callCount on a disabled trace surfaces FiloNotEnabledException', function (): void {
    expect(fn () => Assert::callCount(new Trace([], 1, null, false), 'x', atMost: 1))
        ->toThrow(Filo\Testing\FiloNotEnabledException::class);
});

test('runsUnder fails at exactly the limit (strictly under)', function (): void {
    $t = new Trace([], 10_000_000);
    expect(fn () => Assert::runsUnder($t, 10))
        ->toThrow(ExpectationFailed::class, 'took 10 ms, limit 10 ms');
});

<?php

declare(strict_types=1);

use Filo\Collector;

beforeEach(fn () => Collector::begin());

test('mark returns the next event id', function (): void {
    expect(Collector::mark())->toBe(0);
    $id = Collector::enter('a', '/f.php', 1);
    Collector::leave($id);
    expect(Collector::mark())->toBe(1);
});

test('since returns only events recorded after the mark', function (): void {
    $before = Collector::enter('before', '/f.php', 1);
    Collector::leave($before);

    $mark = Collector::mark();
    $a = Collector::enter('a', '/f.php', 2);
    $b = Collector::enter('b', '/f.php', 3);
    Collector::leave($b);
    Collector::leave($a);

    $events = Collector::since($mark);

    expect($events)->toHaveCount(2)
        ->and(array_column($events, 'fn'))->toBe(['a', 'b'])
        ->and($events[1]['p'])->toBe($a);
});

test('since rewrites parents that point before the mark to -1', function (): void {
    $outer = Collector::enter('outer', '/f.php', 1);
    $mark  = Collector::mark();
    $inner = Collector::enter('inner', '/f.php', 2);
    Collector::leave($inner);

    $events = Collector::since($mark);

    expect($events)->toHaveCount(1)
        ->and($events[0]['fn'])->toBe('inner')
        ->and($events[0]['p'])->toBe(-1);

    Collector::leave($outer);
});

test('since closes still-open frames without mutating the collector', function (): void {
    $mark = Collector::mark();
    $id   = Collector::enter('open', '/f.php', 1);

    $events = Collector::since($mark);

    expect($events[0]['e'])->toBeGreaterThanOrEqual($events[0]['s']);

    // The real frame is still open: leaving it now must still work and set a later end.
    Collector::leave($id);
    $after = Collector::since($mark);
    expect($after[0]['e'])->toBeGreaterThanOrEqual($events[0]['e']);
});

test('since on a mark past the end returns an empty list', function (): void {
    expect(Collector::since(0))->toBe([])
        ->and(Collector::since(10))->toBe([]);
});

test('capped reports the collector cap state', function (): void {
    expect(Collector::capped())->toBeFalse();
});

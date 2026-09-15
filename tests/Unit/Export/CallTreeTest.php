<?php

declare(strict_types=1);

use Filo\Export\CallTree;
use Filo\Export\TraceFile;
use Filo\Tests\Support\SampleTraces;

test('a call tree knows each call\'s children, caller and self time', function (): void {
    $tree = new CallTree(SampleTraces::request());

    expect($tree->roots())->toBe([0])
        ->and($tree->children(0))->toBe([1, 2, 4])
        ->and($tree->children(2))->toBe([3])
        ->and($tree->children(4))->toBe([])
        ->and($tree->caller(3))->toBe(2)
        ->and($tree->caller(0))->toBeNull()
        ->and($tree->duration(2))->toBe(200)
        ->and(array_map($tree->selfTime(...), [0, 1, 2, 3, 4]))->toBe([350, 200, 150, 50, 250]);
});

test('a call whose caller is not in the trace hangs off the top, and children run in start order', function (): void {
    $tree = new CallTree(TraceFile::fromArray(['version' => 1, 'events' => [
        SampleTraces::event(0, -1, 'a', '/x.php', 1, 0, 100),
        SampleTraces::event(1, 0, 'late', '/x.php', 2, 60, 90),
        SampleTraces::event(2, 0, 'early', '/x.php', 3, 10, 50),
        SampleTraces::event(3, 7, 'orphan', '/x.php', 4, 120, 130),
    ]], 'x.json'));

    expect($tree->roots())->toBe([0, 3])
        ->and($tree->children(0))->toBe([2, 1])
        ->and($tree->caller(3))->toBeNull();
});

test('a call within an earlier call\'s span is nested under it, as the viewer nests it', function (): void {
    // b() ran inside a(), but the trace flattened the chain: both say r() called them.
    $tree = new CallTree(TraceFile::fromArray(['version' => 1, 'duration' => 100, 'events' => [
        SampleTraces::event(0, -1, 'r', '/x.php', 1, 0, 100),
        SampleTraces::event(1, 0, 'a', '/x.php', 2, 10, 80),
        SampleTraces::event(2, 0, 'b', '/x.php', 3, 20, 40),
        SampleTraces::event(3, 0, 'c', '/x.php', 4, 85, 95),
    ]], 'x.json'));

    expect($tree->children(0))->toBe([1, 3])
        ->and($tree->children(1))->toBe([2])
        ->and($tree->caller(2))->toBe(1)
        ->and(array_map($tree->selfTime(...), [0, 1, 2, 3]))->toBe([20, 50, 20, 10])
        ->and($tree->rootTime())->toBe(100);
});

test('overlapping calls count once in their caller\'s self time', function (): void {
    $tree = new CallTree(TraceFile::fromArray(['version' => 1, 'events' => [
        SampleTraces::event(0, -1, 'r', '/x.php', 1, 0, 100),
        SampleTraces::event(1, 0, 'x', '/x.php', 2, 10, 60),
        SampleTraces::event(2, 0, 'y', '/x.php', 3, 50, 90),
    ]], 'x.json'));

    expect($tree->selfTime(0))->toBe(20); // 100 minus 10-90, not minus 50 + 40
});

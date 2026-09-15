<?php

declare(strict_types=1);

use Filo\Export\CachegrindExporter;
use Filo\Export\TraceFile;
use Filo\Tests\Support\SampleTraces;

function exportCachegrind(TraceFile $trace): string
{
    $out = fopen('php://memory', 'w+b');
    (new CachegrindExporter())->write($trace, $out);
    rewind($out);

    return (string) stream_get_contents($out);
}

test('cachegrind sums the trace up per function, with who called whom', function (): void {
    // Times in 10 ns units; {main} calls the root and its self time is what's left.
    $expected = <<<'TXT'
        version: 1
        creator: filo
        cmd: GET /orders
        part: 1
        positions: line

        events: Time_(10ns)
        summary: 100

        fl=(1) php:internal
        fn=(1) {main}
        0 0
        cfl=(2) /app/Http/Kernel.php
        cfn=(2) App\Http\Kernel::handle
        calls=1 10
        0 100

        fl=(2)
        fn=(2)
        10 35
        cfl=(3) /app/Repo.php
        cfn=(3) App\Repo::find
        calls=2 20
        10 40
        cfl=(4) /app/View.php
        cfn=(4) App\View::render
        calls=1 30
        10 25

        fl=(3)
        fn=(3)
        20 35
        cfl=(3)
        cfn=(5) {closure:App\Repo::find():22}
        calls=1 22
        20 5

        fl=(3)
        fn=(5)
        22 5

        fl=(4)
        fn=(4)
        30 25
        TXT;

    expect(exportCachegrind(SampleTraces::request()))->toBe($expected . "\n\n");
});

test('a capped trace says so, and names stay on one line', function (): void {
    $trace = TraceFile::fromArray([
        'version' => 1, 'duration' => 10, 'capped' => true, 'context' => ['sapi' => 'cli', 'argv' => ["a\nb"]],
        'events'  => [SampleTraces::event(0, -1, 'f', "/x\n.php", 1, 0, 10)],
    ], 'x.json');

    expect(exportCachegrind($trace))->toContain("cmd: a b\n")
        ->toContain("# filo stopped recording part-way (capped)")
        ->toContain("fl=(2) /x .php\n");
});

test('the self times add up to the summary, even where the trace flattened a chain', function (): void {
    $out = exportCachegrind(TraceFile::fromArray(['version' => 1, 'duration' => 1000, 'events' => [
        SampleTraces::event(0, -1, 'r', '/x.php', 1, 0, 900),
        SampleTraces::event(1, 0, 'a', '/x.php', 2, 100, 800),
        SampleTraces::event(2, 0, 'b', '/x.php', 3, 200, 400), // inside a(), flattened under r()
        SampleTraces::event(3, 0, 'b', '/x.php', 3, 500, 700), // the same
    ]], 'x.json'));

    preg_match('/^summary: (\d+)$/m', $out, $summary);
    preg_match_all('/^fn=.*\n\d+ (\d+)$/m', $out, $self);
    expect((int) $summary[1])->toBe(100)
        ->and(array_sum(array_map('intval', $self[1])))->toBe(100)
        ->and($out)->toContain("cfn=(3) a\ncalls=1 2\n1 70\n")      // r() calls a() once
        ->and($out)->toContain("cfn=(4) b\ncalls=2 3\n2 40\n");     // a() calls b() twice
});

test('the export is named like Xdebug names its profiles', function (): void {
    expect((new CachegrindExporter())->fileName(SampleTraces::request()))->toBe('cachegrind.out.20260915-100000-000001-abcd')
        ->and((new CachegrindExporter())->fileName(TraceFile::fromArray(['version' => 1, 'events' => []], 'tests/App_FooTest__testBar.json')))
        ->toBe('cachegrind.out.App_FooTest__testBar');
});

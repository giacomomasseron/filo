<?php

declare(strict_types=1);

use Filo\Tests\Support\TempProject;

/** Runs bin/filo in the project at $root; returns [exit code, output]. */
function filoRun(string $root, array $args, array $env = []): array
{
    $env = array_merge(getenv(), ['FILO_PROJECT_ROOT' => $root, 'FILO_ENABLED' => '', 'FILO_KEEP' => '', 'FILO_EXCLUDE' => '', 'FILO_BREAK_TIMEOUT' => ''], $env);
    $p   = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/bin/filo', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);

    return [proc_close($p), $out];
}

/** Runs git in $dir; false when git isn't available. */
function gitIn(string $dir, string ...$args): bool
{
    $p = @proc_open(['git', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir);
    if ($p === false) {
        return false;
    }
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);

    return proc_close($p) === 0;
}

test('filo on turns tracing on with .filo-on', function (): void {
    $root = TempProject::root();

    [$code, $out] = filoRun($root, ['on']);

    expect($code)->toBe(0, $out)
        ->and($root . '/.filo-on')->toBeFile();
});

test('filo off removes .filo-on', function (): void {
    $root = TempProject::root();
    touch($root . '/.filo-on');

    [$code, $out] = filoRun($root, ['off']);

    expect($code)->toBe(0, $out)
        ->and(is_file($root . '/.filo-on'))->toBeFalse();
});

test('filo off warns when FILO_ENABLED keeps tracing on anyway', function (): void {
    [$code, $out] = filoRun(TempProject::root(), ['off'], ['FILO_ENABLED' => '1']);

    expect($code)->toBe(0, $out)
        ->and($out)->toContain('FILO_ENABLED');
});

test('filo clear deletes traces and test artifacts, not paused requests or breakpoints', function (): void {
    $root = TempProject::root();
    foreach (['traces/a.json', 'traces/b.json', 'traces/tests/FooTest__testBar.json', 'traces/breaks/120000-abc.json', 'breakpoints.json'] as $f) {
        // is_dir() first: under filo, PHPUnit still sees an @-silenced warning.
        is_dir(dirname($root . '/.filo/' . $f)) || mkdir(dirname($root . '/.filo/' . $f), 0777, true);
        file_put_contents($root . '/.filo/' . $f, '{}');
    }

    [$code, $out] = filoRun($root, ['clear']);

    expect($code)->toBe(0, $out)
        ->and(glob($root . '/.filo/traces/*.json'))->toBe([])
        ->and(glob($root . '/.filo/traces/tests/*.json'))->toBe([])
        ->and($root . '/.filo/traces/breaks/120000-abc.json')->toBeFile()
        ->and($root . '/.filo/breakpoints.json')->toBeFile();
});

test('filo --version prints the installed version', function (): void {
    [$code, $out] = filoRun(TempProject::root(), ['--version']);

    expect($code)->toBe(0, $out)
        ->and(trim($out))->toMatch('/^filo \S+$/')
        ->and(trim($out))->not->toBe('filo unknown');
});

test('filo doctor shows each setting and where it comes from', function (): void {
    $root = TempProject::root();
    file_put_contents($root . '/filo.json', '{"keep": 50}');

    [$code, $out] = filoRun($root, ['doctor'], ['FILO_BREAK_TIMEOUT' => '7']);

    expect($code)->toBe(0, $out)
        ->and($out)->toMatch('/exclude\s+vendor\s+default/')
        ->and($out)->toMatch('/keep\s+50\s+filo\.json/')
        ->and($out)->toMatch('/breakTimeout\s+7 s\s+FILO_BREAK_TIMEOUT/');
});

test('filo doctor says whether tracing is on, and why', function (): void {
    $root = TempProject::root();

    [, $off] = filoRun($root, ['doctor']);
    touch($root . '/.filo-on');
    [, $on] = filoRun($root, ['doctor']);

    expect($off)->toMatch('/tracing\s+off/')
        ->and($on)->toMatch('/tracing\s+on \(\.filo-on\)/');
});

test('filo doctor shows the project root and how it was found', function (): void {
    $root = TempProject::root();

    [, $out] = filoRun($root, ['doctor']);

    expect($out)->toContain(realpath($root) ?: $root)
        ->and($out)->toContain('FILO_PROJECT_ROOT');
});

test('filo doctor fails on a filo.json value it can\'t use, and names it', function (): void {
    $root = TempProject::root();
    file_put_contents($root . '/filo.json', '{"keep": -1}');

    [$code, $out] = filoRun($root, ['doctor']);

    expect($code)->toBe(1)
        ->and($out)->toContain('"keep" should be');
});

test('filo doctor fails when git would commit .filo/', function (): void {
    $root = TempProject::root();
    if (!gitIn($root, 'init', '-q')) {
        $this->markTestSkipped('needs git');
    }

    [$code, $out] = filoRun($root, ['doctor']);
    file_put_contents($root . '/.gitignore', ".filo/\n");
    [$fixedCode] = filoRun($root, ['doctor']);

    expect($code)->toBe(1)
        ->and($out)->toContain('.gitignore')
        ->and($fixedCode)->toBe(0);
});

<?php

declare(strict_types=1);

use Filo\Tests\Support\TempProject;

/**
 * Runs $call in a traced child process with $breakpoint (a function name,
 * a "file.php:line" string, an entry in the web UI's object form, or a
 * Closure that builds one from the project root). If the child pauses,
 * reads the snapshot and hands it to $release, which by default does what
 * `filo continue <id>` does.
 *
 * @param array<string, string> $env extra environment for the child
 * @param null|Closure(array, string): void $release gets the snapshot and the project root
 * @return array{?string, string, string, float} [raw snapshot JSON (null if it never paused), child output, project root, wall ms]
 */
function pauseAndSnapshot(string|array|Closure $breakpoint, string $functions, string $call, array $env = [], ?Closure $release = null): array
{
    $release ??= static function (array $snapshot, string $root): void {
        touch($root . '/.filo/traces/breaks/' . $snapshot['id'] . '.continue');
    };
    $root = TempProject::root();
    mkdir($root . '/.filo');
    $breakpoint = $breakpoint instanceof Closure ? $breakpoint($root) : $breakpoint;
    file_put_contents($root . '/.filo/breakpoints.json', json_encode(['breakpoints' => [$breakpoint]]));
    file_put_contents($root . '/functions.php', "<?php\n" . $functions);
    file_put_contents($root . '/main.php', strtr(<<<'PHP'
        <?php
        require AUTOLOAD;
        require __DIR__ . '/functions.php';
        echo CALL;
        PHP, ['AUTOLOAD' => var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true), 'CALL' => $call]));

    $env  = array_merge(getenv(), [
        'FILO_ENABLED'       => '1',
        'FILO_PROJECT_ROOT'  => $root,
        'FILO_CACHE_DIR'     => $root . '/cache',
        'FILO_BREAK_TIMEOUT' => '10',
    ], $env);
    $start = hrtime(true);
    $proc  = proc_open(
        [PHP_BINARY, '-d', 'opcache.enable_cli=0', $root . '/main.php'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root,
        $env,
    );

    $breaks   = $root . '/.filo/traces/breaks';
    $snapshot = null;
    for ($i = 0; $i < 100 && $snapshot === null; $i++) {
        usleep(100_000);
        foreach (glob($breaks . '/*.json') ?: [] as $f) {
            $raw = (string) file_get_contents($f);
            if (is_array($decoded = json_decode($raw, true))) { // skip a half-written file
                $snapshot = $raw;
                $release($decoded, $root);
            }
        }
        if ($snapshot === null && !proc_get_status($proc)['running']) {
            break; // finished without pausing
        }
    }
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    proc_close($proc);

    return [$snapshot, $out, $root, (hrtime(true) - $start) / 1e6];
}

test('a breakpoint snapshot never contains #[SensitiveParameter] values', function (): void {
    [$snapshot, $out] = pauseAndSnapshot(
        'filo_bp_login',
        'function filo_bp_login(string $user, #[\SensitiveParameter] string $password): string { return "ok"; }',
        "filo_bp_login('bob', 'hunter2')",
    );

    expect($snapshot)->not->toBeNull('the breakpoint never paused: ' . $out)
        ->and($out)->toBe('ok');

    $vars = json_decode($snapshot, true)['vars'];
    expect($vars['user'])->toBe('bob')
        ->and($vars)->toHaveKey('password')
        ->and($snapshot)->not->toContain('hunter2');
});

test('an enabled breakpoint in the web UI format pauses', function (): void {
    [$snapshot, $out] = pauseAndSnapshot(
        ['id' => 'bp_ui', 'fn' => 'filo_bp_ui', 'enabled' => true],
        'function filo_bp_ui(int $n): int { return $n; }',
        'filo_bp_ui(7)',
    );

    expect($snapshot)->not->toBeNull('the breakpoint never paused: ' . $out)
        ->and(json_decode($snapshot, true)['vars']['n'])->toBe(7)
        ->and($out)->toBe('7');
});

test('a disabled breakpoint does not pause', function (): void {
    [$snapshot, $out] = pauseAndSnapshot(
        ['id' => 'bp_off', 'fn' => 'filo_bp_off', 'enabled' => false],
        'function filo_bp_off(): string { return "ok"; }',
        'filo_bp_off()',
    );

    expect($snapshot)->toBeNull()
        ->and($out)->toBe('ok');
});

test('a breakpoint can target one closure by its name', function (): void {
    // functions.php puts the function on line 2 (after "<?php").
    [$snapshot, $out] = pauseAndSnapshot(
        '{closure:filo_bp_outer():2}',
        'function filo_bp_outer(): string { return (function (int $n) { return "ok"; })(3); }',
        'filo_bp_outer()',
    );

    expect($snapshot)->not->toBeNull('the breakpoint never paused: ' . $out)
        ->and(json_decode($snapshot, true)['fn'])->toBe('{closure:filo_bp_outer():2}')
        ->and(json_decode($snapshot, true)['vars']['n'])->toBe(3)
        ->and($out)->toBe('ok');
});

test('a paused request nobody releases continues after FILO_BREAK_TIMEOUT', function (): void {
    [$snapshot, $out, $root, $ms] = pauseAndSnapshot(
        'filo_bp_wait',
        'function filo_bp_wait(): string { return "ok"; }',
        'filo_bp_wait()',
        ['FILO_BREAK_TIMEOUT' => '1'],
        static function (): void {}, // nobody releases it
    );

    $traces = glob($root . '/.filo/traces/*.json') ?: [];
    expect($snapshot)->not->toBeNull('the breakpoint never paused: ' . $out)
        ->and($out)->toBe('ok')
        ->and($ms)->toBeGreaterThan(900) // it really waited for the timeout
        ->and(glob($root . '/.filo/traces/breaks/*.json'))->toBe([]) // snapshot cleaned up
        ->and($traces)->toHaveCount(1)
        ->and(json_decode((string) file_get_contents($traces[0]), true)['duration'] / 1e6)
        ->toBeLessThan(1000); // the 1 s pause is not in the trace
});

test('filo continue --all releases a paused request', function (): void {
    [$snapshot, $out, , $ms] = pauseAndSnapshot(
        'filo_bp_all',
        'function filo_bp_all(): string { return "ok"; }',
        'filo_bp_all()',
        ['FILO_BREAK_TIMEOUT' => '30'],
        static function (array $snapshot, string $root): void {
            $p = proc_open(
                [PHP_BINARY, dirname(__DIR__, 2) . '/bin/filo', 'continue', '--all'],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                $root,
                array_merge(getenv(), ['FILO_PROJECT_ROOT' => $root]),
            );
            stream_get_contents($pipes[1]);
            proc_close($p);
        },
    );

    expect($snapshot)->not->toBeNull('the breakpoint never paused: ' . $out)
        ->and($out)->toBe('ok')
        ->and($ms)->toBeLessThan(10_000); // released, not timed out after 30 s
});

test('a file:line breakpoint pauses at the entry of the function containing that line', function (): void {
    // filo_bp_line() spans lines 2-5 of functions.php; the breakpoint is on line 4.
    [$snapshot, $out] = pauseAndSnapshot(
        'functions.php:4',
        "function filo_bp_line(int \$n): int {\n    \$double = \$n * 2;\n    return \$double + 1;\n}",
        'filo_bp_line(20)',
    );

    $snap = json_decode((string) $snapshot, true);
    expect($snapshot)->not->toBeNull('the breakpoint never paused: ' . $out)
        ->and($snap['fn'])->toBe('filo_bp_line')
        ->and($snap['line'])->toBe(2)
        ->and($snap['vars']['n'])->toBe(20)
        ->and($out)->toBe('41');
});

test('a file:line breakpoint in a closure pauses at the closure, not the function around it', function (): void {
    // filo_bp_nest() spans lines 2-7 and the closure in it lines 3-5: line 4 is the closure's.
    [$snapshot, $out] = pauseAndSnapshot(
        static fn (string $root): array => ['file' => $root . '/functions.php', 'line' => 4, 'enabled' => true],
        "function filo_bp_nest(): int {\n    \$add = function (int \$n): int {\n        return \$n + 1;\n    };\n    return \$add(41);\n}",
        'filo_bp_nest()',
    );

    $snap = json_decode((string) $snapshot, true);
    expect($snapshot)->not->toBeNull('the breakpoint never paused: ' . $out)
        ->and($snap['fn'])->toBe('{closure:filo_bp_nest():3}')
        ->and($snap['vars']['n'])->toBe(41)
        ->and($out)->toBe('42');
});

test('a file:line breakpoint outside the closure pauses at the function around it', function (): void {
    // The same code: line 6 is filo_bp_nest()'s own.
    [$snapshot, $out] = pauseAndSnapshot(
        static fn (string $root): array => ['file' => $root . '/functions.php', 'line' => 6, 'enabled' => true],
        "function filo_bp_nest(): int {\n    \$add = function (int \$n): int {\n        return \$n + 1;\n    };\n    return \$add(41);\n}",
        'filo_bp_nest()',
    );

    expect($snapshot)->not->toBeNull('the breakpoint never paused: ' . $out)
        ->and(json_decode((string) $snapshot, true)['fn'])->toBe('filo_bp_nest')
        ->and($out)->toBe('42');
});

test('a file:line breakpoint outside every function never pauses', function (): void {
    [$snapshot, $out] = pauseAndSnapshot(
        'functions.php:1',
        'function filo_bp_nowhere(): string { return "ok"; }',
        'filo_bp_nowhere()',
    );

    expect($snapshot)->toBeNull()
        ->and($out)->toBe('ok');
});

<?php

declare(strict_types=1);

use Filo\Tests\Support\TempProject;

/**
 * Runs $call in a traced child process with $breakpoint (a function name,
 * or an entry in the web UI's object form) and, if the child pauses, reads
 * the snapshot and releases it.
 *
 * @return array{?string, string} [raw snapshot JSON (null if it never paused), child output]
 */
function pauseAndSnapshot(string|array $breakpoint, string $functions, string $call): array
{
    $root = TempProject::root();
    mkdir($root . '/.filo');
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
    ]);
    $proc = proc_open(
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
                touch($breaks . '/' . $decoded['id'] . '.continue');
            }
        }
        if ($snapshot === null && !proc_get_status($proc)['running']) {
            break; // finished without pausing
        }
    }
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    proc_close($proc);

    return [$snapshot, $out];
}

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

<?php

declare(strict_types=1);

use Filo\Tests\Support\TempProject;

/**
 * Tracer::cycle() is the public per-request boundary for long-running
 * runtimes (Octane, RoadRunner, FrankenPHP workers): it writes the trace so
 * far and starts a fresh one.
 *
 * @return array{int, string, list<list<string>>} [exit code, output, function names per trace file]
 */
function runCycles(bool $enabled): array
{
    $root = TempProject::root();
    file_put_contents($root . '/work.php', "<?php\nfunction filo_cycle_a(): int { return 1; }\nfunction filo_cycle_b(): int { return 2; }\n");
    file_put_contents($root . '/main.php', strtr(<<<'PHP'
        <?php
        require AUTOLOAD;
        require __DIR__ . '/work.php';
        filo_cycle_a();
        \Filo\Tracer::cycle(); // "request" 1 ends here
        filo_cycle_b();        // "request" 2, flushed at shutdown
        echo 'done';
        PHP, ['AUTOLOAD' => var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true)]));

    $env = array_merge(getenv(), [
        'FILO_ENABLED'      => $enabled ? '1' : '0',
        'FILO_PROJECT_ROOT' => $root,
        'FILO_CACHE_DIR'    => $root . '/cache',
    ]);
    $p = proc_open(
        [PHP_BINARY, '-d', 'opcache.enable_cli=0', $root . '/main.php'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root,
        $env,
    );
    $out  = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    $code = proc_close($p);

    $traces = [];
    foreach (glob($root . '/.filo/traces/*.json') ?: [] as $f) {
        $traces[] = array_column(json_decode((string) file_get_contents($f), true)['events'], 'fn');
    }
    sort($traces);

    return [$code, $out, $traces];
}

test('Tracer::cycle() ends one trace and starts the next', function (): void {
    [$code, $out, $traces] = runCycles(true);

    expect($code)->toBe(0, $out)
        ->and($out)->toBe('done')
        ->and($traces)->toBe([['filo_cycle_a'], ['filo_cycle_b']]);
});

test('Tracer::cycle() does nothing when filo is off', function (): void {
    [$code, $out, $traces] = runCycles(false);

    expect($code)->toBe(0, $out)
        ->and($out)->toBe('done')
        ->and($traces)->toBe([]);
});

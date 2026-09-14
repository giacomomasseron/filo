<?php

declare(strict_types=1);

use Filo\Tests\Support\TempProject;

/**
 * Runs $calls instrumented calls in a traced child process under
 * memory_limit=$limit, after the script itself allocated $ballastMb.
 *
 * @return array{int, string, list<array>} [exit code, output, traces]
 */
function runUnderMemoryLimit(string $limit, int $ballastMb, int $calls): array
{
    $root = TempProject::root();
    file_put_contents($root . '/work.php', "<?php\nfunction filo_mem_tick(): int { return 1; }\n");
    file_put_contents($root . '/main.php', strtr(<<<'PHP'
        <?php
        require AUTOLOAD;
        require __DIR__ . '/work.php';
        $ballast = str_repeat('x', BALLAST * 1024 * 1024);
        for ($i = 0; $i < CALLS; $i++) {
            filo_mem_tick();
        }
        echo 'done';
        PHP, [
        'AUTOLOAD' => var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true),
        'BALLAST'  => (string) $ballastMb,
        'CALLS'    => (string) $calls,
    ]));

    $env = array_merge(getenv(), [
        'FILO_ENABLED'      => '1',
        'FILO_PROJECT_ROOT' => $root,
        'FILO_CACHE_DIR'    => $root . '/cache',
    ]);
    $p = proc_open(
        [PHP_BINARY, '-d', 'memory_limit=' . $limit, '-d', 'opcache.enable_cli=0', $root . '/main.php'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root,
        $env,
    );
    $out  = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    $code = proc_close($p);

    $traces = array_map(
        static fn (string $f): array => json_decode((string) file_get_contents($f), true),
        glob($root . '/.filo/traces/*.json') ?: [],
    );

    return [$code, $out, $traces];
}

test('a runaway loop cannot exhaust memory_limit through filo', function (): void {
    [$code, $out, $traces] = runUnderMemoryLimit('64M', 0, 600_000);

    expect($code)->toBe(0, $out)
        ->and($out)->toBe('done')
        ->and($traces)->toHaveCount(1)
        ->and($traces[0]['capped'])->toBeTrue();
});

test('filo stops recording when the app itself nears memory_limit', function (): void {
    // ~109 MB of 128 MB is the app's before the loop starts: a quarter of
    // the limit on top of that (~23 MB of events) would exhaust it.
    [$code, $out, $traces] = runUnderMemoryLimit('128M', 105, 600_000);

    expect($code)->toBe(0, $out)
        ->and($out)->toBe('done')
        ->and($traces)->toHaveCount(1)
        ->and($traces[0]['capped'])->toBeTrue();
});

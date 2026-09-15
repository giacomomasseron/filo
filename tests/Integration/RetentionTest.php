<?php

declare(strict_types=1);

use Filo\Tests\Support\TempProject;

/**
 * Every traced request writes a file; only the newest `keep` request traces
 * stay (FILO_KEEP / filo.json "keep", default 200, 0 = keep all). Per-test
 * artifacts in tests/ are never pruned: there's one per test at most.
 *
 * Runs $traces "requests" in one child process (Tracer::cycle() between
 * them), request $i calling filo_keep_$i().
 *
 * @return array{int, string, string, list<list<string>>} [exit code, output, project root, functions per remaining trace]
 */
function writeTraces(int $traces, array $env = [], ?string $filoJson = null): array
{
    $root = TempProject::root();
    if ($filoJson !== null) {
        file_put_contents($root . '/filo.json', $filoJson);
    }
    @mkdir($root . '/.filo/traces/tests', 0777, true);
    file_put_contents($root . '/.filo/traces/tests/SomeTest__testIt.json', '{}');

    $work = "<?php\n";
    $main = "<?php\nrequire " . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ";\nrequire __DIR__ . '/work.php';\n";
    for ($i = 0; $i < $traces; $i++) {
        $work .= "function filo_keep_$i(): int { return $i; }\n";
        $main .= "filo_keep_$i();\n" . ($i < $traces - 1 ? "\\Filo\\Tracer::cycle();\n" : '');
    }
    file_put_contents($root . '/work.php', $work);
    file_put_contents($root . '/main.php', $main . "echo 'done';\n");

    $env = array_merge(getenv(), [
        'FILO_ENABLED'      => '1',
        'FILO_PROJECT_ROOT' => $root,
        'FILO_CACHE_DIR'    => $root . '/cache',
        'FILO_KEEP'         => '',
    ], $env);
    $p    = proc_open([PHP_BINARY, '-d', 'opcache.enable_cli=0', $root . '/main.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env);
    $out  = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    $code = proc_close($p);

    $left = [];
    foreach (glob($root . '/.filo/traces/*.json') ?: [] as $f) {
        $left[] = array_column(json_decode((string) file_get_contents($f), true)['events'] ?? [], 'fn');
    }
    sort($left);

    return [$code, $out, $root, $left];
}

test('only the newest FILO_KEEP request traces are kept', function (): void {
    [$code, $out, , $left] = writeTraces(5, ['FILO_KEEP' => '2']);

    expect($code)->toBe(0, $out)
        ->and($left)->toBe([['filo_keep_3'], ['filo_keep_4']]);
});

test('keep can come from filo.json', function (): void {
    [$code, $out, , $left] = writeTraces(4, filoJson: '{"keep": 1}');

    expect($code)->toBe(0, $out)
        ->and($left)->toBe([['filo_keep_3']]);
});

test('keep 0 keeps every trace', function (): void {
    [$code, $out, , $left] = writeTraces(4, ['FILO_KEEP' => '0']);

    expect($code)->toBe(0, $out)
        ->and($left)->toHaveCount(4);
});

test('per-test artifacts are never pruned', function (): void {
    [$code, $out, $root] = writeTraces(3, ['FILO_KEEP' => '1']);

    expect($code)->toBe(0, $out)
        ->and($root . '/.filo/traces/tests/SomeTest__testIt.json')->toBeFile();
});

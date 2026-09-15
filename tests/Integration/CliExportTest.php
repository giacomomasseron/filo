<?php

declare(strict_types=1);

use Filo\Tests\Support\TempProject;

/** Runs `bin/filo export` in the project at $root; returns [exit code, output]. */
function filoExport(string $root, string ...$args): array
{
    $p   = proc_open(
        [PHP_BINARY, dirname(__DIR__, 2) . '/bin/filo', 'export', ...$args],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root,
        array_merge(getenv(), ['FILO_PROJECT_ROOT' => $root]),
    );
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);

    return [proc_close($p), $out];
}

/** A project with two request traces and a test's trace. */
function projectWithTracesToExport(): string
{
    $root  = TempProject::root();
    $dir   = $root . '/.filo/traces';
    $trace = static fn (array $context): string => (string) json_encode(['version' => 1, 'duration' => 300, 'capped' => false, 'context' => $context, 'events' => [
        ['i' => 0, 'p' => -1, 'fn' => 'App\Kernel::handle', 'file' => '/app/Kernel.php', 'line' => 5, 's' => 0, 'e' => 300, 'm' => 0],
        ['i' => 1, 'p' => 0, 'fn' => 'App\Repo::find', 'file' => '/app/Repo.php', 'line' => 9, 's' => 100, 'e' => 200, 'm' => 0],
    ]]);
    mkdir($dir . '/tests', 0777, true);
    file_put_contents($dir . '/20260915-100000-000001-aaaa.json', $trace(['sapi' => 'fpm-fcgi', 'method' => 'GET', 'uri' => '/older']));
    file_put_contents($dir . '/20260915-100001-000001-bbbb.json', $trace(['sapi' => 'fpm-fcgi', 'method' => 'GET', 'uri' => '/newest']));
    file_put_contents($dir . '/tests/App_FooTest__testBar.json', $trace(['sapi' => 'cli', 'test' => 'App\FooTest::testBar', 'status' => 'failed']));

    return $root;
}

test('filo export writes the latest trace to .filo/exports, as cachegrind by default', function (): void {
    $root = projectWithTracesToExport();

    [$code, $out] = filoExport($root);

    $file = $root . '/.filo/exports/cachegrind.out.20260915-100001-000001-bbbb';
    expect($code)->toBe(0, $out)
        ->and($out)->toContain('wrote ')->toContain('2 calls of GET /newest')->toContain('PhpStorm')
        ->and(is_file($file))->toBeTrue()
        ->and((string) file_get_contents($file))->toStartWith("version: 1\ncreator: filo\ncmd: GET /newest\n");
});

test('filo export takes a trace name, a format, and -o - for stdout', function (): void {
    $root = projectWithTracesToExport();

    [$code, $out] = filoExport($root, 'tests/App_FooTest__testBar.json', '--format=speedscope', '-o', '-');

    $doc = json_decode($out, true);
    expect($code)->toBe(0, $out)
        ->and($doc['name'])->toBe('App\FooTest::testBar')
        ->and($doc['profiles'][0]['name'])->toBe('tests/App_FooTest__testBar.json')
        ->and(array_column($doc['shared']['frames'], 'name'))->toBe(['App\Kernel::handle', 'App\Repo::find']);
});

test('filo export -o writes where it is told', function (): void {
    $root = projectWithTracesToExport();

    [$code, $out] = filoExport($root, '20260915-100000-000001-aaaa.json', '-f', 'speedscope', '--output=' . $root . '/older.json');

    expect($code)->toBe(0, $out)
        ->and(json_decode((string) file_get_contents($root . '/older.json'), true)['name'])->toBe('GET /older');
});

test('filo export names the problem: an unknown format, or no such trace', function (): void {
    $root = projectWithTracesToExport();

    [$code, $out] = filoExport($root, '--format=pprof');
    expect($code)->toBe(1)->and($out)->toContain('unknown format "pprof": use cachegrind or speedscope');

    [$code, $out] = filoExport($root, 'nope.json');
    expect($code)->toBe(1)->and($out)->toContain('no trace "nope.json"');
});

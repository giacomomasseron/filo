<?php

declare(strict_types=1);

/**
 * Runs the 'fixture' group in a child `pest` process with FILO_PROJECT_ROOT
 * pointed at a temp dir, then asserts which artifact files exist there.
 */
function runFixtures(string $root, bool $enabled): array
{
    $env = array_merge(getenv(), [
        'FILO_PROJECT_ROOT' => $root,
        'FILO_ENABLED'      => $enabled ? '1' : '0',
    ]);
    $cmd = [PHP_BINARY, '-d', 'opcache.enable_cli=0', dirname(__DIR__, 2) . '/vendor/bin/pest', '--group', 'fixture', '--no-coverage'];
    $p   = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2), $env);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    $code = proc_close($p);

    return [$code, $out, glob($root . '/.filo/traces/tests/*.json') ?: []];
}

function artifactRoot(): string
{
    $root = sys_get_temp_dir() . '/filo-artifacts-' . getmypid() . '-' . bin2hex(random_bytes(2));
    mkdir($root, 0777, true);
    file_put_contents($root . '/composer.json', '{}'); // looksLikeProject()

    return $root;
}

test('failing and #[Traced] tests produce artifacts; untraced passing tests do not', function (): void {
    $root = artifactRoot();
    [$code, $out, $files] = runFixtures($root, true);

    expect($code)->not->toBe(0, $out); // the fixture group contains a failing test

    // Pest compiles closure tests to methods on a generated P\... class; the
    // exact method name is Pest-version-dependent (observed on Pest 3.8.x /
    // PHP 8.5: `__pest_evaluable_<slug>`). Assert the contract instead of the
    // literal name: exactly 2 artifacts, one failed (the closure fixture),
    // one traced (the #[Traced] method on the class-based fixture).
    expect($files)->toHaveCount(2, $out);

    $decoded = array_map(
        static fn (string $file): array => ['name' => basename($file), 'trace' => json_decode((string) file_get_contents($file), true)],
        $files,
    );

    $failed = array_values(array_filter(
        $decoded,
        static fn (array $d): bool => $d['trace']['context']['status'] === 'failed' && str_contains($d['name'], 'FixtureFailingTest'),
    ));
    expect($failed)->toHaveCount(1, $out);

    $traced = array_values(array_filter(
        $decoded,
        static fn (array $d): bool => $d['trace']['context']['status'] === 'traced' && str_contains($d['name'], 'FixtureTracedTest__testTracedPasses'),
    ));
    expect($traced)->toHaveCount(1, $out);

    // No process-wide trace at exit: only the tests/ subdir exists.
    expect(glob($root . '/.filo/traces/*.json'))->toBe([]);
});

test('without FILO_ENABLED the extension is a no-op', function (): void {
    $root = artifactRoot();
    [, , $files] = runFixtures($root, false);

    expect($files)->toBe([]);
});

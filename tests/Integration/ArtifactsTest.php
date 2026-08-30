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
    $names = array_map('basename', $files);
    sort($names);

    // Pest compiles closure tests to methods on a generated P\... class;
    // the exact method name is Pest-version-dependent (observed on Pest
    // 3.8.x / PHP 8.5: `__pest_evaluable_<slug>`). The count (2) and the
    // two statuses below are the contract, not this literal name.
    expect($names)->toBe([
        'Filo_Tests_Fixtures_FixtureTracedTest__testTracedPasses.json',
        'P_Tests_Fixtures_FixtureFailingTest____pest_evaluable_fixture__deliberately_failing_test.json',
    ]);

    $failed = json_decode((string) file_get_contents($root . '/.filo/traces/tests/' . $names[1]), true);
    expect($failed['context']['status'])->toBe('failed');
    $traced = json_decode((string) file_get_contents($root . '/.filo/traces/tests/' . $names[0]), true);
    expect($traced['context']['status'])->toBe('traced');

    // No process-wide trace at exit: only the tests/ subdir exists.
    expect(glob($root . '/.filo/traces/*.json'))->toBe([]);
});

test('without FILO_ENABLED the extension is a no-op', function (): void {
    $root = artifactRoot();
    [, , $files] = runFixtures($root, false);

    expect($files)->toBe([]);
});

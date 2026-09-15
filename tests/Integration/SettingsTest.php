<?php

declare(strict_types=1);

use Filo\Settings;
use Filo\Tests\Support\TempProject;

/**
 * filo.json in the project root holds settings, so people who turn filo on
 * with .filo-on (Herd, Valet: no easy env vars) can configure it, and a
 * team can share them. Precedence: env var > filo.json > default.
 *
 * @return array{int, string, list<string>} [exit code, output, functions traced]
 */
function runWithFiloJson(?string $filoJson, array $env = []): array
{
    $root = TempProject::root();
    if ($filoJson !== null) {
        file_put_contents($root . '/filo.json', $filoJson);
    }
    mkdir($root . '/skipme');
    file_put_contents($root . '/skipme/a.php', "<?php\nfunction filo_set_skipped(): int { return 1; }\n");
    file_put_contents($root . '/b.php', "<?php\nfunction filo_set_kept(): int { return 2; }\n");
    file_put_contents($root . '/main.php', "<?php\nrequire " . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true)
        . ";\nrequire __DIR__ . '/skipme/a.php';\nrequire __DIR__ . '/b.php';\necho filo_set_skipped() + filo_set_kept();\n");

    $env = array_merge(getenv(), [
        'FILO_ENABLED'      => '1',
        'FILO_PROJECT_ROOT' => $root,
        'FILO_CACHE_DIR'    => $root . '/cache',
        'FILO_EXCLUDE'      => '',
    ], $env);
    $p    = proc_open([PHP_BINARY, '-d', 'opcache.enable_cli=0', $root . '/main.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env);
    $out  = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    $code = proc_close($p);

    $fns = [];
    foreach (glob($root . '/.filo/traces/*.json') ?: [] as $f) {
        array_push($fns, ...array_column(json_decode((string) file_get_contents($f), true)['events'] ?? [], 'fn'));
    }

    return [$code, $out, $fns];
}

test('exclude in filo.json keeps matching files uninstrumented', function (): void {
    [$code, $out, $fns] = runWithFiloJson('{"exclude": ["vendor", "skipme"]}');

    expect($code)->toBe(0, $out)
        ->and($out)->toBe('3')
        ->and($fns)->toContain('filo_set_kept')
        ->and($fns)->not->toContain('filo_set_skipped');
});

test('FILO_EXCLUDE beats filo.json', function (): void {
    [$code, $out, $fns] = runWithFiloJson('{"exclude": ["skipme"]}', ['FILO_EXCLUDE' => 'vendor']);

    expect($code)->toBe(0, $out)
        ->and($fns)->toContain('filo_set_skipped');
});

test('a broken filo.json is ignored, never fatal', function (): void {
    [$code, $out, $fns] = runWithFiloJson('{"exclude": ');

    expect($code)->toBe(0, $out)
        ->and($out)->toBe('3')
        ->and($fns)->toContain('filo_set_skipped');
});

test('each setting comes from the env var, else filo.json, else its default', function (): void {
    $root = TempProject::root();
    file_put_contents($root . '/filo.json', '{"keep": 50, "breakTimeout": 30}');
    putenv('FILO_BREAK_TIMEOUT=5');
    try {
        $s = Settings::load($root);
    } finally {
        putenv('FILO_BREAK_TIMEOUT');
    }

    expect([$s['exclude'], $s['keep'], $s['breakTimeout']])->toBe([['vendor'], 50, 5])
        ->and($s['sources'])->toBe(['exclude' => 'default', 'keep' => 'filo.json', 'breakTimeout' => 'FILO_BREAK_TIMEOUT'])
        ->and($s['problems'])->toBe([]);
});

test('a filo.json value of the wrong type falls back and is reported', function (): void {
    $root = TempProject::root();
    file_put_contents($root . '/filo.json', '{"keep": "lots", "colour": "blue"}');

    $s = Settings::load($root);

    expect($s['keep'])->toBe(200)
        ->and($s['sources']['keep'])->toBe('default')
        ->and($s['problems'])->toHaveCount(2); // bad "keep", unknown "colour"
});

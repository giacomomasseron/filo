<?php

declare(strict_types=1);

use Filo\Tests\Support\TempProject;

/** Runs bin/filo against the project at $root; returns [exit code, output]. */
function filoCli(string $root, string ...$args): array
{
    $env = array_merge(getenv(), ['FILO_PROJECT_ROOT' => $root]);
    $p   = proc_open(
        [PHP_BINARY, dirname(__DIR__, 2) . '/bin/filo', ...$args],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root,
        $env,
    );
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);

    return [proc_close($p), $out];
}

/** A project whose breakpoints.json was last written by the web UI. */
function projectWithUiBreakpoints(): string
{
    $root = TempProject::root();
    mkdir($root . '/.filo');
    file_put_contents($root . '/.filo/breakpoints.json', json_encode(['breakpoints' => [
        ['id' => 'bp_off', 'fn' => 'App\Off::paused', 'enabled' => false],
        ['id' => 'bp_line', 'file' => '/app/src/Foo.php', 'line' => 12, 'enabled' => true],
        ['id' => 'bp_on', 'fn' => 'App\On::live', 'enabled' => true],
    ]]));

    return $root;
}

function savedBreakpoints(string $root): array
{
    return json_decode((string) file_get_contents($root . '/.filo/breakpoints.json'), true)['breakpoints'];
}

test('filo break keeps the breakpoints the web UI created', function (): void {
    $root = projectWithUiBreakpoints();

    [$code, $out] = filoCli($root, 'break', 'App\New::added');

    $saved = savedBreakpoints($root);
    expect($code)->toBe(0, $out)
        ->and($saved)->toHaveCount(4)
        ->and(array_slice($saved, 0, 3))->toBe([
            ['id' => 'bp_off', 'fn' => 'App\Off::paused', 'enabled' => false],
            ['id' => 'bp_line', 'file' => '/app/src/Foo.php', 'line' => 12, 'enabled' => true],
            ['id' => 'bp_on', 'fn' => 'App\On::live', 'enabled' => true],
        ])
        ->and($saved[3])->toMatchArray(['fn' => 'App\New::added', 'enabled' => true]);
});

test('filo unbreak removes only the named breakpoint', function (): void {
    $root = projectWithUiBreakpoints();

    [$code, $out] = filoCli($root, 'unbreak', 'App\On::live');

    expect($code)->toBe(0, $out)
        ->and(savedBreakpoints($root))->toBe([
            ['id' => 'bp_off', 'fn' => 'App\Off::paused', 'enabled' => false],
            ['id' => 'bp_line', 'file' => '/app/src/Foo.php', 'line' => 12, 'enabled' => true],
        ]);
});

test('filo break on a disabled breakpoint re-enables it instead of adding a duplicate', function (): void {
    $root = projectWithUiBreakpoints();

    filoCli($root, 'break', 'App\Off::paused');

    $saved = savedBreakpoints($root);
    expect($saved)->toHaveCount(3)
        ->and($saved[0])->toBe(['id' => 'bp_off', 'fn' => 'App\Off::paused', 'enabled' => true]);
});

test('filo breaks lists disabled and file:line breakpoints too', function (): void {
    $root = projectWithUiBreakpoints();

    [$code, $out] = filoCli($root, 'breaks');

    expect($code)->toBe(0, $out)
        ->and($out)->toContain('App\Off::paused')
        ->and($out)->toContain('/app/src/Foo.php:12')
        ->and($out)->toContain('App\On::live');
});

test('filo break and unbreak take a file and line too', function (): void {
    $root = projectWithUiBreakpoints();

    [$code, $out] = filoCli($root, 'break', 'src/Checkout.php:42');
    expect($code)->toBe(0, $out)
        ->and($out)->toContain('breakpoint added: src/Checkout.php:42')
        ->and(savedBreakpoints($root)[3])->toMatchArray(['file' => 'src/Checkout.php', 'line' => 42, 'enabled' => true])
        ->and(filoCli($root, 'breaks')[1])->toContain('src/Checkout.php:42');

    // The same file by its absolute path is the same breakpoint.
    filoCli($root, 'break', $root . '/src/Checkout.php:42');
    expect(savedBreakpoints($root))->toHaveCount(4);

    [$code, $out] = filoCli($root, 'unbreak', 'src/Checkout.php:42');
    expect($code)->toBe(0, $out)
        ->and(array_column(savedBreakpoints($root), 'id'))->toBe(['bp_off', 'bp_line', 'bp_on']);
});

<?php

declare(strict_types=1);

/**
 * Tracer::findProjectRoot() decides where .filo-on, filo.json and .filo/
 * are looked for, and it has been gotten wrong once. Each case runs in a
 * child process against a copy of src/Tracer.php placed in a real layout,
 * so __DIR__, the working directory and SCRIPT_FILENAME are the real thing.
 */

/** A fresh, empty temp dir (no project markers of its own). */
function rootLayout(): string
{
    $base = sys_get_temp_dir() . '/filo-root-' . getmypid() . '-' . bin2hex(random_bytes(3));
    mkdir($base, 0777, true);

    return $base;
}

/** Creates each directory (trailing "/") or file under $base. */
function rootMake(string $base, string ...$paths): void
{
    foreach ($paths as $path) {
        $full = $base . '/' . $path;
        if (str_ends_with($path, '/')) {
            @mkdir($full, 0777, true);
            continue;
        }
        @mkdir(dirname($full), 0777, true);
        file_put_contents($full, $path === 'composer.json' || str_ends_with($path, '/composer.json') ? '{}' : '');
    }
}

/** A copy of src/Tracer.php at $base/$dir/Tracer.php. */
function rootTracer(string $base, string $dir): string
{
    @mkdir($base . '/' . $dir, 0777, true);
    copy(dirname(__DIR__, 2) . '/src/Tracer.php', $base . '/' . $dir . '/Tracer.php');

    return $base . '/' . $dir . '/Tracer.php';
}

/** What findProjectRoot() returns, run from $cwd (as `php -r`, or as $script when given). */
function rootFoundFrom(string $tracer, string $cwd, ?string $script = null, array $env = []): string
{
    $code = 'require ' . var_export($tracer, true) . '; echo Filo\Tracer::findProjectRoot();';
    if ($script !== null) {
        file_put_contents($script, "<?php\n" . $code);
    }
    $cmd = $script !== null ? [PHP_BINARY, $script] : [PHP_BINARY, '-r', $code];
    $env = array_merge(getenv(), ['FILO_PROJECT_ROOT' => '', 'FILO_ENABLED' => '0'], $env);

    $p   = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $env);
    $out = trim((string) stream_get_contents($pipes[1]));
    $err = (string) stream_get_contents($pipes[2]);
    proc_close($p);

    return $err === '' ? $out : "ERROR: $err";
}

function expectSameDir(string $actual, string $expected): void
{
    expect(realpath($actual))->toBe(realpath($expected), "got $actual");
}

test('FILO_PROJECT_ROOT wins', function (): void {
    $base = rootLayout();
    rootMake($base, 'forced/', 'app/composer.json');
    $tracer = rootTracer($base, 'app/vendor/giacomomasseron/filo/src');

    expectSameDir(rootFoundFrom($tracer, $base . '/app', env: ['FILO_PROJECT_ROOT' => $base . '/forced/']), $base . '/forced');
});

test('an installed package finds the project four levels up', function (): void {
    $base = rootLayout();
    rootMake($base, 'app/composer.json');
    $tracer = rootTracer($base, 'app/vendor/giacomomasseron/filo/src');

    expectSameDir(rootFoundFrom($tracer, $base), $base . '/app');
});

test('a package outside the project is found from the running script', function (): void {
    // A Composer path repository: the package lives outside the app.
    $base = rootLayout();
    rootMake($base, 'app/composer.json', 'app/public/', 'elsewhere/');
    $tracer = rootTracer($base, 'a/b/packages/filo/src');

    expectSameDir(rootFoundFrom($tracer, $base . '/elsewhere', $base . '/app/public/index.php'), $base . '/app');
});

test('a package outside the project is found from the working directory', function (): void {
    // `artisan serve` and most web servers run PHP with cwd = public/.
    $base = rootLayout();
    rootMake($base, 'app/composer.json', 'app/public/');
    $tracer = rootTracer($base, 'a/b/packages/filo/src');

    expectSameDir(rootFoundFrom($tracer, $base . '/app/public'), $base . '/app');
});

test('a .filo folder marks a project that has no composer.json', function (): void {
    $base = rootLayout();
    rootMake($base, 'site/.filo/', 'site/sub/');
    $tracer = rootTracer($base, 'a/b/packages/filo/src');

    expectSameDir(rootFoundFrom($tracer, $base . '/site/sub'), $base . '/site');
});

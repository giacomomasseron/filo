<?php

declare(strict_types=1);

use Filo\Breakpoints;
use Filo\Tracer;

test('include beats exclude, and filo itself is never traced', function (): void {
    $include = [Breakpoints::pathKey('/app/vendor/acme/billing/'), Breakpoints::pathKey('/app/vendor/x/one.php')];
    $never   = [Breakpoints::pathKey('/app/vendor/giacomomasseron/filo/'), '/nikic/php-parser/'];
    $traces  = static fn (string $path): bool => Tracer::traces($path, $include, ['/vendor/'], $never);

    expect($traces('/app/src/Checkout.php'))->toBeTrue()
        ->and($traces('/app/vendor/laravel/framework/src/Router.php'))->toBeFalse()
        ->and($traces('/app/vendor/acme/billing/src/Charge.php'))->toBeTrue()
        ->and($traces('/app/vendor/x/one.php'))->toBeTrue()
        ->and($traces('/app/vendor/x/one.php.dist.php'))->toBeFalse() // a file entry isn't a prefix
        ->and($traces('/app/vendor/giacomomasseron/filo/src/Collector.php'))->toBeFalse()
        ->and($traces('/app/vendor/nikic/php-parser/lib/PhpParser/Parser.php'))->toBeFalse()
        ->and($traces('/home/me/acme-vendor-portal/app/Checkout.php'))->toBeTrue()
        ->and($traces('C:\app\vendor\laravel\Router.php'))->toBeFalse(); // backslashes compare as /
});

test('include paths resolve against the project root, and ones that match nothing are dropped', function (): void {
    $root = sys_get_temp_dir() . '/filo-paths-' . getmypid() . '-' . bin2hex(random_bytes(3));
    mkdir($root . '/vendor/acme/billing', 0777, true);
    file_put_contents($root . '/one.php', '<?php');
    try {
        [$include, $exclude] = Tracer::pathRules(['vendor/acme/billing', $root . '/one.php', 'missing'], ['/vendor/', 'app\Legacy', ''], $root);

        expect($include)->toBe([
            Breakpoints::pathKey((string) realpath($root . '/vendor/acme/billing')) . '/',
            Breakpoints::pathKey((string) realpath($root . '/one.php')),
        ])->and($exclude)->toBe(['/vendor/', Breakpoints::pathKey('app\Legacy')]);
    } finally {
        unlink($root . '/one.php');
        rmdir($root . '/vendor/acme/billing');
        rmdir($root . '/vendor/acme');
        rmdir($root . '/vendor');
        rmdir($root);
    }
});

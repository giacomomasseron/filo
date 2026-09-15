<?php

declare(strict_types=1);

use Filo\Breakpoints;

test('a path and a line name a file breakpoint; anything else names a function', function (): void {
    expect(Breakpoints::normalize('App\Foo::bar'))->toMatchArray(['fn' => 'App\Foo::bar'])
        ->and(Breakpoints::normalize('{closure:/app/src/x.php:3}'))->toMatchArray(['fn' => '{closure:/app/src/x.php:3}'])
        ->and(Breakpoints::normalize('App\Foo::bar:12'))->toMatchArray(['fn' => 'App\Foo::bar:12'])
        ->and(Breakpoints::normalize('app/Foo.php:42'))->toMatchArray(['file' => 'app/Foo.php', 'line' => 42, 'enabled' => true])
        ->and(Breakpoints::normalize('C:\app\Foo.php:7'))->toMatchArray(['file' => 'C:\app\Foo.php', 'line' => 7])
        ->and(Breakpoints::normalize(['fn' => 'app/Foo.php:42', 'enabled' => false]))
        ->toMatchArray(['file' => 'app/Foo.php', 'line' => 42, 'enabled' => false]);
});

test('file breakpoints compare by their resolved path', function (): void {
    $at = static fn (string $file, int $line): array => ['id' => 'x', 'file' => $file, 'line' => $line, 'enabled' => true];

    expect(Breakpoints::fileKey('src/Foo.php', '/proj'))->toBe(Breakpoints::pathKey('/proj/src/Foo.php'))
        ->and(Breakpoints::fileKey('/elsewhere/Foo.php', '/proj'))->toBe(Breakpoints::pathKey('/elsewhere/Foo.php'))
        ->and(Breakpoints::fileKey('C:\proj\Foo.php', '/proj'))->toBe(Breakpoints::pathKey('C:\proj\Foo.php'))
        ->and(Breakpoints::same($at('src/Foo.php', 3), $at('/proj/src/Foo.php', 3), '/proj'))->toBeTrue()
        ->and(Breakpoints::same($at('src/Foo.php', 3), $at('src/Foo.php', 4), '/proj'))->toBeFalse()
        ->and(Breakpoints::same($at('src/Foo.php', 3), ['id' => 'y', 'fn' => 'foo', 'enabled' => true], '/proj'))->toBeFalse();
});

<?php

declare(strict_types=1);

use Filo\Collector;
use Filo\Tests\Support\TempProject;

/**
 * Closures are named the PHP 8.4 way, {closure:<enclosing scope>:<line>},
 * on every PHP version, so traces, toCall() patterns and breakpoints read
 * the same everywhere. Expected names are written by hand; on 8.4+ they
 * must also equal what PHP itself calls the closure (__METHOD__ inside it).
 */
function loadClosureNamesFixture(): string
{
    $path = TempProject::fixture('ClosureNames.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace FiloNames;

final class Shop
{
    public function method(): string { return (function () { return __METHOD__; })(); }
    public static function staticMethod(): string { return (function () { return __METHOD__; })(); }
    public function nested(): string { return (function () { return (function () { return __METHOD__; })(); })(); }
    public function inArrow(): string { return (fn () => (function () { return __METHOD__; })())(); }
}

trait Greets
{
    public function greet(): string { return (function () { return __METHOD__; })(); }
}

final class Greeter
{
    use Greets;
}

enum Suit
{
    case Hearts;

    public function label(): string { return (function () { return __METHOD__; })(); }
}

function helper(): string { return (function () { return __METHOD__; })(); }

$GLOBALS['filo_names_top'] = static function (): string { return (function () { return __METHOD__; })(); };
PHP);
    require_once $path;

    return (string) realpath($path);
}

/** Runs one fixture case; returns PHP's own name for its innermost closure. */
function callClosureCase(string $case): string
{
    return match ($case) {
        'method'        => (new FiloNames\Shop())->method(),
        'static method' => FiloNames\Shop::staticMethod(),
        'nested'        => (new FiloNames\Shop())->nested(),
        'arrow fn'      => (new FiloNames\Shop())->inArrow(),
        'trait'         => (new FiloNames\Greeter())->greet(),
        'enum'          => FiloNames\Suit::Hearts->label(),
        'function'      => FiloNames\helper(),
        'top level'     => ($GLOBALS['filo_names_top'])(),
    };
}

beforeEach(function (): void {
    if (!defined('FILO_BOOTSTRAPPED')) {
        $this->markTestSkipped('needs FILO_ENABLED=1');
    }
});

test('closures are named after their enclosing scope and line', function (string $case, array $expected, bool $likePhp84): void {
    // Top-level closures are named after the file's real path; PHP uses the
    // path as it was included, which can be spelled differently.
    $expected = str_replace('FILE', loadClosureNamesFixture(), $expected);

    $mark   = Collector::mark();
    $native = callClosureCase($case);

    expect(array_column(Collector::since($mark), 'fn'))->toBe($expected);
    if ($likePhp84 && PHP_VERSION_ID >= 80400) {
        expect($native)->toBe($expected[array_key_last($expected)]);
    }
})->with([
    'in a method'        => ['method', ['FiloNames\Shop::method', '{closure:FiloNames\Shop::method():9}'], true],
    'in a static method' => ['static method', ['FiloNames\Shop::staticMethod', '{closure:FiloNames\Shop::staticMethod():10}'], true],
    'nested'             => ['nested', ['FiloNames\Shop::nested', '{closure:FiloNames\Shop::nested():11}', '{closure:{closure:FiloNames\Shop::nested():11}:11}'], true],
    'inside an arrow fn' => ['arrow fn', ['FiloNames\Shop::inArrow', '{closure:{closure:FiloNames\Shop::inArrow():12}:12}'], true],
    'in a trait method'  => ['trait', ['FiloNames\Greets::greet', '{closure:FiloNames\Greets::greet():17}'], true],
    'in an enum method'  => ['enum', ['FiloNames\Suit::label', '{closure:FiloNames\Suit::label():29}'], true],
    'in a function'      => ['function', ['FiloNames\helper', '{closure:FiloNames\helper():32}'], true],
    'at the top level'   => ['top level', ['{closure:FILE:34}', '{closure:{closure:FILE:34}:34}'], false],
]);

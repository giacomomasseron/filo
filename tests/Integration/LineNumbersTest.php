<?php

declare(strict_types=1);

use Filo\Collector;
use Filo\Tests\Support\TempProject;

/**
 * Instrumented code must keep every line where it was, so exception lines,
 * __LINE__ and stack traces point at the real source — in the user's app
 * and in their test files, which filo instruments too.
 *
 * The fixture's line numbers below (34, 39) are counted by hand.
 */
function loadLineFixture(): void
{
    require_once TempProject::fixture('LineFixture.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace FiloLineFixture;

/**
 * Blank lines, comments and multi-line expressions: everything a pretty
 * printer would reflow.
 */
final class Shop
{
    private const RATES = [
        'a' => 1,

        'b' => 2,
    ];

    public function total(array $items): int
    {
        $sum = 0; // running total


        foreach ($items as $item) {
            $sum += self::RATES[$item]
                ?? 0;
        }

        $check = static function (int $sum): void {
            // a comment, then blank lines


            if ($sum > 10) {
                throw new \LengthException('too much');
            }
        };
        $check($sum);

        throw new \RuntimeException('line ' . __LINE__);
    }
}
PHP);
}

/** @return array{Throwable, list<string>} what $fn threw, and the functions filo recorded meanwhile */
function throwsWhileTraced(Closure $fn): array
{
    $mark = Collector::mark();
    try {
        $fn();
    } catch (Throwable $e) {
        return [$e, array_column(Collector::since($mark), 'fn')];
    }

    throw new LogicException('the fixture did not throw');
}

beforeEach(function (): void {
    if (!defined('FILO_BOOTSTRAPPED')) {
        $this->markTestSkipped('needs FILO_ENABLED=1');
    }
    loadLineFixture();
});

test('an exception in instrumented code reports its real line', function (): void {
    [$e, $fns] = throwsWhileTraced(fn () => (new FiloLineFixture\Shop())->total(['a']));

    expect($fns)->toContain('FiloLineFixture\Shop::total') // it really was instrumented
        ->and($e)->toBeInstanceOf(RuntimeException::class)
        ->and($e->getLine())->toBe(39)
        ->and($e->getMessage())->toBe('line 39'); // __LINE__ too
});

test('an exception inside an instrumented closure reports its real line', function (): void {
    [$e] = throwsWhileTraced(fn () => (new FiloLineFixture\Shop())->total(['a', 'b', 'b', 'b', 'b', 'b']));

    expect($e)->toBeInstanceOf(LengthException::class)
        ->and($e->getLine())->toBe(34);
});

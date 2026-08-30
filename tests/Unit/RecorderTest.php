<?php

declare(strict_types=1);

use Filo\Collector;
use Filo\Testing\Recorder;
use Filo\Testing\Trace;
use Filo\Tests\Support\TempProject;

test('capture returns a Trace with wall time and the closure result', function (): void {
    $t = Recorder::capture(function (): string {
        usleep(5_000);

        return 'done';
    });

    expect($t)->toBeInstanceOf(Trace::class)
        ->and($t->result())->toBe('done')
        ->and($t->wallMs())->toBeGreaterThan(4.0);
});

test('capture slices only the events of the closure', function (): void {
    if (!Recorder::enabled()) {
        $this->markTestSkipped('needs FILO_ENABLED=1');
    }
    $noise = Collector::enter('noise', '/n.php', 1);
    Collector::leave($noise);

    $t = Recorder::capture(function (): void {
        $a = Collector::enter('inside', '/n.php', 2);
        Collector::leave($a);
    });

    expect($t->calls('inside'))->toBe(1)
        ->and($t->calls('noise'))->toBe(0);
});

test('capture instruments real code included through the wrapper', function (): void {
    if (!Recorder::enabled()) {
        $this->markTestSkipped('needs FILO_ENABLED=1');
    }
    require TempProject::fixture('rec_fixture.php', <<<'PHP'
<?php
function filo_rec_leaf(): int { return 1; }
function filo_rec_root(): int { return filo_rec_leaf() + filo_rec_leaf(); }
PHP);

    $t = Recorder::capture(fn (): int => filo_rec_root());

    expect($t->result())->toBe(2)
        ->and($t->calls('filo_rec_root'))->toBe(1)
        ->and($t->calls('filo_rec_leaf'))->toBe(2);
});

test('a throwing closure propagates but still measures', function (): void {
    expect(fn () => Recorder::capture(function (): void {
        throw new LogicException('boom');
    }))->toThrow(LogicException::class, 'boom');
});

test('enabled mirrors FILO_BOOTSTRAPPED', function (): void {
    expect(Recorder::enabled())->toBe(defined('FILO_BOOTSTRAPPED'));
});

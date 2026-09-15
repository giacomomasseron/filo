<?php

declare(strict_types=1);

use Filo\Export\Exporters;
use Filo\Export\ExportException;
use Filo\Export\SpeedscopeExporter;
use Filo\Export\TraceFile;
use Filo\Export\TraceLocator;
use Filo\Tests\Support\SampleTraces;
use Filo\Tests\Support\TempProject;

test('a trace file must be a v1 trace with complete events', function (): void {
    expect(fn () => TraceFile::fromArray(['events' => []], 'a.json'))
        ->toThrow(ExportException::class, 'a.json is not a filo trace: no "version": 1')
        ->and(fn () => TraceFile::fromArray(['version' => 1], 'b.json'))
        ->toThrow(ExportException::class, 'b.json is not a filo trace: no "events" list')
        ->and(fn () => TraceFile::fromArray(['version' => 1, 'events' => [['i' => 0, 'fn' => 'f']]], 'c.json'))
        ->toThrow(ExportException::class, 'c.json is not a filo trace: event #0 lacks i, p, fn, s or e');
});

test('a trace file says what ran: the request, the test or the command', function (): void {
    $labelOf = static fn (array $context): string => TraceFile::fromArray(['version' => 1, 'context' => $context, 'events' => []], 'x.json')->label();

    expect(SampleTraces::request()->label())->toBe('GET /orders')
        ->and($labelOf(['sapi' => 'cli', 'test' => 'App\FooTest::testBar', 'status' => 'failed']))->toBe('App\FooTest::testBar')
        ->and($labelOf(['sapi' => 'cli', 'argv' => ['artisan', 'orders:sync']]))->toBe('artisan orders:sync')
        ->and($labelOf([]))->toBe('x.json');
});

test('a trace file read from disk keeps its duration and events', function (): void {
    $path = TempProject::root() . '/t.json';
    file_put_contents($path, json_encode(['version' => 1, 'duration' => 5, 'capped' => true, 'events' => [SampleTraces::event(0, -1, 'f', '/x.php', 1, 0, 9)]]));

    $trace = TraceFile::fromFile($path, 'tests/t.json');

    expect([$trace->name, $trace->duration, $trace->capped, count($trace->events)])->toBe(['tests/t.json', 9, true, 1])
        ->and(fn () => TraceFile::fromFile($path . '.missing'))->toThrow(ExportException::class, "can't read");
});

test('exporters are found by format name', function (): void {
    expect(Exporters::builtIn()->formats())->toBe(['cachegrind', 'speedscope'])
        ->and(Exporters::builtIn()->get('speedscope'))->toBeInstanceOf(SpeedscopeExporter::class)
        ->and(fn () => Exporters::builtIn()->get('pprof'))
        ->toThrow(ExportException::class, 'unknown format "pprof": use cachegrind or speedscope');
});

test('the locator finds the latest trace, a trace by name, or a path', function (): void {
    $root = TempProject::root();
    $dir  = $root . '/.filo/traces';
    mkdir($dir . '/tests', 0777, true);
    foreach (['20260915-100000-000001-aaaa.json', '20260915-100001-000001-bbbb.json', 'tests/App_FooTest__testBar.json'] as $name) {
        file_put_contents($dir . '/' . $name, '{}');
    }
    $locator = new TraceLocator($dir);

    expect($locator->locate('latest'))->toBe($dir . '/20260915-100001-000001-bbbb.json')
        ->and($locator->locate('tests/App_FooTest__testBar.json'))->toBe($dir . '/tests/App_FooTest__testBar.json')
        ->and($locator->locate($dir . '/20260915-100000-000001-aaaa.json'))->toBe($dir . '/20260915-100000-000001-aaaa.json')
        ->and($locator->nameOf($dir . '/tests/App_FooTest__testBar.json'))->toBe('tests/App_FooTest__testBar.json')
        ->and($locator->nameOf('/elsewhere/trace.json'))->toBe('trace.json')
        ->and(fn () => $locator->locate('nope.json'))->toThrow(ExportException::class, 'no trace "nope.json"')
        ->and(fn () => (new TraceLocator($root . '/empty'))->locate('latest'))->toThrow(ExportException::class, 'no request traces');
});

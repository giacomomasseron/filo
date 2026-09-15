<?php

declare(strict_types=1);

use Filo\Export\SpeedscopeExporter;
use Filo\Export\TraceFile;
use Filo\Tests\Support\SampleTraces;

/** @return array<string, mixed> the decoded export */
function exportSpeedscope(TraceFile $trace): array
{
    $out = fopen('php://memory', 'w+b');
    (new SpeedscopeExporter())->write($trace, $out);
    rewind($out);

    return json_decode((string) stream_get_contents($out), true, 512, JSON_THROW_ON_ERROR);
}

/** @param list<array{type: string, frame: int, at: int}> $events */
function speedscopeEventsAsText(array $events): array
{
    return array_map(static fn (array $e): string => $e['type'] . $e['frame'] . '@' . $e['at'], $events);
}

test('speedscope gets one frame per function and each call as an open and a close', function (): void {
    $doc     = exportSpeedscope(SampleTraces::request());
    $profile = $doc['profiles'][0];

    expect($doc['$schema'])->toBe('https://www.speedscope.app/file-format-schema.json')
        ->and($doc['name'])->toBe('GET /orders')
        ->and(array_column($doc['shared']['frames'], 'name'))
        ->toBe(['App\Http\Kernel::handle', 'App\Repo::find', '{closure:App\Repo::find():22}', 'App\View::render'])
        ->and($doc['shared']['frames'][1])->toBe(['name' => 'App\Repo::find', 'file' => '/app/Repo.php', 'line' => 20])
        ->and($profile)->toMatchArray(['type' => 'evented', 'unit' => 'nanoseconds', 'startValue' => 0, 'endValue' => 1000])
        ->and(speedscopeEventsAsText($profile['events']))
        ->toBe(['O0@0', 'O1@100', 'C1@300', 'O1@400', 'O2@450', 'C2@500', 'C1@600', 'O3@700', 'C3@950', 'C0@1000']);
});

test('a call that outlives its caller is trimmed to fit, so the events stay nested', function (): void {
    // A generator started in a() (0-100) and finished at 150, after a()
    // returned; then b() runs 120-200.
    $events = exportSpeedscope(TraceFile::fromArray(['version' => 1, 'duration' => 200, 'events' => [
        SampleTraces::event(0, -1, 'a', '/x.php', 1, 0, 100),
        SampleTraces::event(1, 0, 'gen', '/x.php', 2, 50, 150),
        SampleTraces::event(2, -1, 'b', '/x.php', 3, 120, 200),
    ]], 'x.json'))['profiles'][0]['events'];

    // Opens and closes pair up like a stack, and time never goes backwards.
    $stack = [];
    $last  = 0;
    foreach ($events as $event) {
        expect($event['at'])->toBeGreaterThanOrEqual($last);
        $last = $event['at'];
        if ($event['type'] === 'O') {
            $stack[] = $event['frame'];
        } else {
            expect(array_pop($stack))->toBe($event['frame']);
        }
    }

    expect($stack)->toBe([])
        ->and(speedscopeEventsAsText($events))->toBe(['O0@0', 'O1@50', 'C1@100', 'C0@100', 'O2@120', 'C2@200']);
});

test('a call within an earlier call\'s span is nested under it, not trimmed', function (): void {
    // b() ran inside a(), but the trace flattened the chain: both say r() called them.
    $events = exportSpeedscope(TraceFile::fromArray(['version' => 1, 'duration' => 100, 'events' => [
        SampleTraces::event(0, -1, 'r', '/x.php', 1, 0, 100),
        SampleTraces::event(1, 0, 'a', '/x.php', 2, 10, 80),
        SampleTraces::event(2, 0, 'b', '/x.php', 3, 20, 40),
        SampleTraces::event(3, 0, 'c', '/x.php', 4, 85, 95),
    ]], 'x.json'))['profiles'][0]['events'];

    expect(speedscopeEventsAsText($events))->toBe(['O0@0', 'O1@10', 'O2@20', 'C2@40', 'C1@80', 'O3@85', 'C3@95', 'C0@100']);
});

test('the export is named after the trace', function (): void {
    expect((new SpeedscopeExporter())->fileName(SampleTraces::request()))->toBe('20260915-100000-000001-abcd.speedscope.json');
});

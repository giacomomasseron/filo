<?php

declare(strict_types=1);

/**
 * Boots server/index.php with php -S on a free port against a temp project
 * root and checks the JSON API contract the designed UI depends on.
 */
final class ApiServer
{
    public static $proc = null;
    public static string $base = '';
    public static string $root = '';

    public static function start(): void
    {
        self::$root = sys_get_temp_dir() . '/filo-api-' . getmypid();
        @mkdir(self::$root . '/.filo/traces/tests', 0777, true);
        file_put_contents(self::$root . '/composer.json', '{}');

        $port = random_int(18000, 19999);
        self::$base = "http://127.0.0.1:$port";
        $env  = array_merge(getenv(), ['FILO_PROJECT_ROOT' => self::$root]);
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        self::$proc = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:$port", dirname(__DIR__, 2) . '/server/index.php'],
            [1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']],
            $pipes,
            null,
            $env,
        );
        for ($i = 0; $i < 50; $i++) {
            usleep(100_000);
            if (@file_get_contents(self::$base . '/api/breakpoints') !== false) {
                return;
            }
        }
        throw new RuntimeException('server did not start');
    }

    public static function stop(): void
    {
        if (self::$proc) {
            proc_terminate(self::$proc);
            proc_close(self::$proc);
        }
    }

    /** @return array{int, mixed} [status, decoded body] */
    public static function call(string $method, string $path, ?string $body = null, array $headers = []): array
    {
        $ctx = stream_context_create(['http' => [
            'method'        => $method,
            'header'        => implode("\r\n", $headers),
            'content'       => $body,
            'ignore_errors' => true,
        ]]);
        $raw    = (string) file_get_contents(self::$base . $path, false, $ctx);
        $status = (int) substr($http_response_header[0] ?? 'HTTP/1.1 0', 9, 3);

        return [$status, json_decode($raw, true)];
    }
}

beforeAll(fn () => ApiServer::start());
afterAll(fn () => ApiServer::stop());

test('mutating endpoints require X-Filo', function (): void {
    [$status] = ApiServer::call('POST', '/api/breaks/continue-all');
    expect($status)->toBe(403);
    [$status, $body] = ApiServer::call('POST', '/api/breaks/continue-all', null, ['X-Filo: 1']);
    expect($status)->toBe(200)->and($body)->toBe(['ok' => true]);
});

test('only requests addressed to a loopback host are served', function (): void {
    // DNS rebinding: a hostile page re-points its own hostname at 127.0.0.1
    // and becomes same-origin with the viewer; its Host header still says so.
    $port = parse_url(ApiServer::$base, PHP_URL_PORT);

    [$status] = ApiServer::call('GET', '/api/breaks', null, ["Host: attacker.example:$port"]);
    expect($status)->toBe(403);

    [$status] = ApiServer::call('GET', '/api/breaks', null, ["Host: localhost:$port"]);
    expect($status)->toBe(200);
});

test('breakpoints round-trip in the UI shape', function (): void {
    [$status, $body] = ApiServer::call('GET', '/api/breakpoints');
    expect($status)->toBe(200)->and($body)->toBe([]);

    [, $saved] = ApiServer::call(
        'PUT',
        '/api/breakpoints',
        json_encode([['fn' => 'App\Foo::bar'], 'plain_fn', ['file' => '/a.php', 'line' => 3, 'enabled' => false]]),
        ['X-Filo: 1', 'Content-Type: application/json'],
    );
    expect($saved)->toHaveCount(3)
        ->and($saved[0])->toMatchArray(['fn' => 'App\Foo::bar', 'enabled' => true])
        ->and($saved[0]['id'])->toStartWith('bp_')
        ->and($saved[1]['fn'])->toBe('plain_fn')
        ->and($saved[2])->toMatchArray(['file' => '/a.php', 'line' => 3, 'enabled' => false]);

    [, $again] = ApiServer::call('GET', '/api/breakpoints');
    expect($again)->toBe($saved);
});

/** @return list<array{i:int,p:int,fn:string,file:string,line:int,s:int,e:int,m:int}> */
function apiTraceEvents(int $n): array
{
    return array_map(
        static fn (int $i): array => ['i' => $i, 'p' => -1, 'fn' => "f$i", 'file' => '/a.php', 'line' => 1, 's' => $i, 'e' => $i + 1, 'm' => 0],
        range(0, $n - 1),
    );
}

test('/api/traces lists summaries newest first, including tests/ artifacts', function (): void {
    $write = function (string $name, string $ts, int $events, int $mtime): void {
        $path = ApiServer::$root . '/.filo/traces/' . $name;
        file_put_contents($path, json_encode([
            'version' => 1, 'ts' => $ts, 'duration' => 1, 'capped' => false,
            'context' => ['sapi' => 'cli'], 'events' => apiTraceEvents($events),
        ]));
        touch($path, $mtime); // mtime, not name, decides the order
    };
    // The tests/ artifact is the OLDEST: a name sort would put it first.
    $write('20260101-000000-aaaa.json', 'old', 2, 1_800_000_200);
    $write('20260102-000000-bbbb.json', 'new', 3, 1_800_000_300);
    $write('tests/FooTest__bar.json', 'test', 1, 1_800_000_100);
    file_put_contents(ApiServer::$root . '/.filo/traces/breaks-not-a-trace.json', '{"nope":true}');

    [$status, $list] = ApiServer::call('GET', '/api/traces');
    expect($status)->toBe(200)
        ->and(array_column($list, 'name'))->toBe(['20260102-000000-bbbb.json', '20260101-000000-aaaa.json', 'tests/FooTest__bar.json'])
        ->and($list[1])->toBe([
            'version' => 1, 'ts' => 'old', 'duration' => 1, 'capped' => false, 'context' => ['sapi' => 'cli'],
            'events_count' => 2, 'name' => '20260101-000000-aaaa.json',
        ]);

    [$status, $one] = ApiServer::call('GET', '/api/traces/tests/FooTest__bar.json');
    expect($status)->toBe(200)->and($one['ts'])->toBe('test')->and($one['events'])->toHaveCount(1);

    [$status] = ApiServer::call('GET', '/api/traces/../composer.json');
    expect($status)->toBe(404);
});

test('trace summaries count events without decoding them, whatever the file shape', function (): void {
    $dir   = ApiServer::$root . '/.filo/traces/';
    $trace = fn (array $context, int $events): array => [
        'version' => 1, 'ts' => 'x', 'duration' => 1, 'capped' => false, 'context' => $context, 'events' => apiTraceEvents($events),
    ];

    // 3000 events span several 64 KB reads. Pad the context until an event's
    // opening {"i": straddles the first read boundary: it must count once.
    for ($pad = 0; ; $pad++) {
        $json = (string) json_encode($trace(['sapi' => 'cli', 'pad' => str_repeat('x', $pad)], 3000));
        $at   = strpos($json, '{"i":', 65536 - 4);
        if ($at !== false && $at < 65536) {
            break;
        }
    }
    file_put_contents($dir . '20270101-000000-000000-big0.json', $json);
    // Pretty-printed isn't a shape filo writes: decoded whole instead.
    file_put_contents($dir . '20270101-000000-000000-pret.json', json_encode($trace(['sapi' => 'cli'], 5), JSON_PRETTY_PRINT));

    [, $list] = ApiServer::call('GET', '/api/traces');
    $byName   = array_column($list, null, 'name');
    expect($byName['20270101-000000-000000-big0.json']['events_count'])->toBe(3000)
        ->and($byName['20270101-000000-000000-big0.json']['context']['pad'])->toBe(str_repeat('x', $pad))
        ->and($byName['20270101-000000-000000-pret.json']['events_count'])->toBe(5)
        ->and($byName['20270101-000000-000000-pret.json'])->not->toHaveKey('events');
});

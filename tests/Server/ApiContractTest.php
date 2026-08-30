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
        $env = array_merge(getenv(), ['FILO_PROJECT_ROOT' => self::$root]);
        self::$proc = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:$port", dirname(__DIR__, 2) . '/server/index.php'],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
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

test('/api/traces returns full traces newest first, including tests/ artifacts', function (): void {
    $trace = fn (string $ts) => json_encode(['version' => 1, 'ts' => $ts, 'duration' => 1, 'capped' => false, 'context' => ['sapi' => 'cli'], 'events' => []]);
    file_put_contents(ApiServer::$root . '/.filo/traces/20260101-000000-aaaa.json', $trace('old'));
    file_put_contents(ApiServer::$root . '/.filo/traces/20260102-000000-bbbb.json', $trace('new'));
    file_put_contents(ApiServer::$root . '/.filo/traces/tests/FooTest__bar.json', $trace('test'));
    file_put_contents(ApiServer::$root . '/.filo/traces/breaks-not-a-trace.json', '{"nope":true}');

    [$status, $list] = ApiServer::call('GET', '/api/traces');
    expect($status)->toBe(200)
        ->and(array_column($list, 'name'))->toBe(['tests/FooTest__bar.json', '20260102-000000-bbbb.json', '20260101-000000-aaaa.json'])
        ->and($list[1])->toHaveKeys(['version', 'ts', 'duration', 'capped', 'context', 'events', 'name']);

    [$status, $one] = ApiServer::call('GET', '/api/traces/tests/FooTest__bar.json');
    expect($status)->toBe(200)->and($one['ts'])->toBe('test');

    [$status] = ApiServer::call('GET', '/api/traces/../composer.json');
    expect($status)->toBe(404);
});

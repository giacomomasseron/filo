<?php

declare(strict_types=1);

/*
 * CI check against a real app: a fresh Laravel project with filo installed,
 * served by `php -S` with opcache on (one long-lived process sharing one
 * opcache across requests, like PHP-FPM). While `.filo-on` exists every
 * request is traced; once it's gone, requests run untraced — and never run
 * instrumented code left behind in the opcache.
 *
 *     php laravel-smoke.php <laravel app dir>
 */

$app = rtrim($argv[1] ?? '', '/\\');
if ($app === '' || !is_file($app . '/artisan')) {
    fwrite(STDERR, "usage: php laravel-smoke.php <laravel app dir>\n");
    exit(2);
}

// A route that reports whether instrumented code ran: its closure is hooked
// when traced, and a hook loads Filo\Collector, which an untraced request
// never does.
$routes = $app . '/routes/web.php';
if (!str_contains((string) file_get_contents($routes), '/filo-check')) {
    file_put_contents($routes, "\nRoute::get('/filo-check', function () {\n    return ['collector' => class_exists(\\Filo\\Collector::class, false)];\n});\n", FILE_APPEND);
}

$port   = 8765;
$log    = $app . '/storage/logs/php-server.log';
$server = proc_open(
    [
        PHP_BINARY,
        '-d', 'opcache.enable=1',
        '-d', 'opcache.validate_timestamps=1',
        '-d', 'opcache.revalidate_freq=0',
        '-S', "127.0.0.1:$port",
        '-t', $app . '/public',
        $app . '/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php',
    ],
    [1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
    $pipes,
    // Laravel's router takes the working directory as public/, as under
    // `artisan serve` — which also makes filo find the project root from there.
    $app . '/public',
);

$fail = static function (string $message) use ($server, $log): never {
    proc_terminate($server);
    fwrite(STDERR, "FAIL: $message\n--- php -S log ---\n" . @file_get_contents($log) . "\n");
    exit(1);
};

/** @return array{int, string} [status, body] */
$get = static function (string $path) use ($port): array {
    $ctx  = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 60]]);
    $body = @file_get_contents("http://127.0.0.1:$port$path", false, $ctx);

    return [(int) substr($http_response_header[0] ?? 'HTTP/1.1 0', 9, 3), (string) $body];
};

$traces = static fn (): array => glob($app . '/.filo/traces/*.json') ?: [];

/** Waits briefly for the shutdown flush; returns the trace files added since $before. */
$newTraces = static function (array $before) use ($traces): array {
    for ($i = 0; $i < 20; $i++) {
        $new = array_values(array_diff($traces(), $before));
        if ($new !== []) {
            return $new;
        }
        usleep(100_000);
    }

    return [];
};

$collectorLoaded = static function (string $when) use ($get, $fail): bool {
    [$status, $body] = $get('/filo-check');
    $status === 200 || $fail("$when: /filo-check answered $status");

    return (bool) (json_decode($body, true)['collector'] ?? $fail("$when: /filo-check body: $body"));
};

for ($i = 0; $i < 60 && $get('/')[0] === 0; $i++) {
    usleep(500_000);
}

$marker = $app . '/.filo-on';
@unlink($marker);

// 1. Untraced: opcache now holds the original files.
$before = $traces();
[$status] = $get('/');
$status === 200 || $fail("untraced / answered $status");
!$collectorLoaded('untraced') || $fail('an untraced request ran instrumented code');
$newTraces($before) === [] || $fail('an untraced request wrote a trace');
echo "PASS  untraced requests: 200, no trace, no instrumented code\n";

// 2. Traced: the warm opcache must not bypass the wrapper.
touch($marker);
$before = $traces();
[$status] = $get('/');
$status === 200 || $fail("traced / answered $status");
$new = $newTraces($before);
count($new) === 1 || $fail('expected one new trace, got ' . count($new));
$fns = array_column(json_decode((string) file_get_contents($new[0]), true)['events'] ?? [], 'fn');
$routeClosure = array_filter($fns, static fn (string $fn): bool => str_starts_with($fn, '{closure:')
    && str_contains(str_replace('\\', '/', $fn), 'routes/web.php'));
$routeClosure !== [] || $fail('the trace has no routes/web.php closure; functions: ' . implode(', ', array_slice($fns, 0, 20)));
$collectorLoaded('traced') || $fail('a traced request did not run instrumented code');
echo 'PASS  traced request: 200, trace with ', count($fns), ' events incl. ', reset($routeClosure), "\n";

// 3. Untraced again: instrumented code must not linger in the opcache.
unlink($marker);
$before = $traces();
[$status] = $get('/');
$status === 200 || $fail("untraced / answered $status after tracing");
!$collectorLoaded('untraced after tracing') || $fail('instrumented code ran after tracing was turned off');
$newTraces($before) === [] || $fail('a request wrote a trace after tracing was turned off');
echo "PASS  untraced again: 200, no trace, no instrumented code left in the opcache\n";

proc_terminate($server);

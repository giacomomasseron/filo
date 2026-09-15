<?php

declare(strict_types=1);

/*
 * What tracing costs a real request: the laravel job's fresh app (filo
 * installed like a release), served by `php -S` the way PHP-FPM would serve
 * it, timed from the client as the median of 20 requests to `/`.
 *
 * Traced requests run with opcache off (filo switches it off for them), so
 * the untraced request is timed with opcache on and off: between those two
 * rows is opcache, between untraced without opcache and traced is filo.
 *
 *     php laravel-overhead.php <laravel app dir>
 *
 * Prints a Markdown table, also appended to $GITHUB_STEP_SUMMARY in CI.
 */

const REQUESTS = 20;

$app = rtrim($argv[1] ?? '', '/\\');
if ($app === '' || !is_file($app . '/artisan')) {
    fwrite(STDERR, "usage: php laravel-overhead.php <laravel app dir>\n");
    exit(2);
}

$servers = [];
$fail    = static function (string $message) use (&$servers): never {
    array_map('proc_terminate', $servers);
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
};

/** Milliseconds one GET / takes on $port. */
$time = static function (int $port) use ($fail): float {
    $ctx    = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 60]]);
    $t      = hrtime(true);
    @file_get_contents("http://127.0.0.1:$port/", false, $ctx);
    $ms     = (hrtime(true) - $t) / 1e6;
    $status = (int) substr($http_response_header[0] ?? 'HTTP/1.1 0', 9, 3);
    $status === 200 || $fail("GET / on port $port answered $status");

    return $ms;
};

$median = static function (int $port) use ($time): float {
    for ($i = 0; $i < 3; $i++) {
        $time($port); // warm up: opcache, Laravel's own caches
    }
    $ms = [];
    for ($i = 0; $i < REQUESTS; $i++) {
        $ms[] = $time($port);
    }
    sort($ms);

    return $ms[intdiv(REQUESTS, 2)];
};

$serve = static function (int $port, bool $opcache) use ($app, &$servers, $fail): void {
    $log       = "$app/storage/logs/php-server-$port.log";
    $servers[] = proc_open(
        [
            PHP_BINARY,
            '-d', 'opcache.enable=' . ($opcache ? '1' : '0'),
            '-d', 'opcache.validate_timestamps=1',
            '-d', 'opcache.revalidate_freq=0',
            '-S', "127.0.0.1:$port",
            '-t', "$app/public",
            "$app/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php",
        ],
        [1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
        $pipes,
        "$app/public", // as under `artisan serve`, see laravel-smoke.php
    );
    for ($i = 0; $i < 60; $i++) {
        if (@file_get_contents("http://127.0.0.1:$port/") !== false) {
            return;
        }
        usleep(500_000);
    }
    $fail("php -S on port $port did not start, see $log");
};

$marker = "$app/.filo-on";
@unlink($marker);

$serve(8766, true);
$serve(8767, false);
$untraced  = $median(8766);
$noOpcache = $median(8767);

// Traced requests go to the opcache server: filo switches opcache off for
// each of them, as under PHP-FPM. The first one after emptying filo's cache
// instruments every app file the request includes.
$cache = getenv('FILO_CACHE_DIR') ?: sys_get_temp_dir() . '/filo-cache';
if (is_dir($cache)) {
    $all = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($cache, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($all as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
}
touch($marker);
$first  = $time(8766);
$traced = $median(8766);
unlink($marker);
array_map('proc_terminate', $servers);

$traces = glob("$app/.filo/traces/*.json") ?: [];
sort($traces); // named down to the microsecond: the last is the newest
$events = count(json_decode((string) file_get_contents((string) end($traces)), true)['events'] ?? []);
$events > 0 || $fail('the traced requests recorded no calls');

$md = "### A fresh Laravel app, traced and untraced\n\n"
    . sprintf("PHP %s · `php -S` · median of %d requests to `/`, timed from the client\n\n", PHP_VERSION, REQUESTS)
    . "| Request | Time |\n|---|--:|\n";
foreach ([
    ['Untraced (filo installed, tracing off), opcache on', $untraced],
    ['Untraced, opcache off', $noOpcache],
    ['Traced (filo turns opcache off), app files already instrumented', $traced],
    ['First traced request, empty instrumentation cache (one request)', $first],
] as [$what, $ms]) {
    $md .= sprintf("| %s | %.1f ms |\n", $what, $ms);
}
$md .= sprintf("\nEach traced request recorded %d calls: `vendor/` isn't traced by default.\n", $events);

echo $md;
$summary = getenv('GITHUB_STEP_SUMMARY');
if (is_string($summary) && $summary !== '') {
    file_put_contents($summary, $md . "\n", FILE_APPEND);
}

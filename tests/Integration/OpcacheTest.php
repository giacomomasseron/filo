<?php

declare(strict_types=1);

use Filo\Tests\Support\TempProject;

/**
 * Opcache caches whatever the compiler was handed — including the
 * instrumented source the wrapper serves. php -S (cli-server) keeps one
 * opcache across requests, like FPM, so it reproduces both ways that goes
 * wrong when `.filo-on` is toggled between requests.
 */
final class OpcacheServer
{
    public static $proc = null;
    public static string $base = '';
    public static string $root = '';

    public static function start(): void
    {
        self::$root = TempProject::root();
        file_put_contents(self::$root . '/index.php', strtr(<<<'PHP'
            <?php
            if ($_SERVER['REQUEST_URI'] === '/ping') { echo 'pong'; return; }
            require AUTOLOAD;
            $f = $_GET['f'];
            $file = __DIR__ . '/' . $f . '.php';
            $wasCached = opcache_is_script_cached($file);
            require $file;
            echo json_encode([
                'result'           => ('filo_oc_' . $f)(),
                'collector_loaded' => class_exists('Filo\Collector', false),
                'was_cached'       => $wasCached,
                'now_cached'       => opcache_is_script_cached($file),
                'opcache'          => (opcache_get_status(false) ?: [])['opcache_enabled'] ?? false,
            ]);
            PHP, ['AUTOLOAD' => var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true)]));

        $port = random_int(20000, 21999);
        self::$base = "http://127.0.0.1:$port";
        $env  = array_merge(getenv(), [
            'FILO_ENABLED'      => '0', // the marker file decides, per request
            'FILO_PROJECT_ROOT' => self::$root,
            'FILO_CACHE_DIR'    => self::$root . '/cache',
        ]);
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        self::$proc = proc_open(
            [
                PHP_BINARY,
                '-d', 'opcache.enable=1',
                '-d', 'opcache.validate_timestamps=1',
                '-d', 'opcache.revalidate_freq=0',
                // Default 2 s: files younger than that are never cached, and
                // these fixtures are written right before they are requested.
                '-d', 'opcache.file_update_protection=0',
                '-S', "127.0.0.1:$port", self::$root . '/index.php',
            ],
            [1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']],
            $pipes,
            self::$root,
            $env,
        );
        for ($i = 0; $i < 50; $i++) {
            usleep(100_000);
            if (@file_get_contents(self::$base . '/ping') === 'pong') {
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
    public static function get(string $path): array
    {
        $ctx    = stream_context_create(['http' => ['ignore_errors' => true]]);
        $raw    = (string) file_get_contents(self::$base . $path, false, $ctx);
        $status = (int) substr($http_response_header[0] ?? 'HTTP/1.1 0', 9, 3);

        return [$status, json_decode($raw, true) ?? $raw];
    }

    public static function trace(bool $on): void
    {
        $marker = self::$root . '/.filo-on';
        if ($on) {
            touch($marker);
        } elseif (is_file($marker)) {
            unlink($marker);
        }
    }

    /** Every function name recorded in any trace written so far. */
    public static function tracedFunctions(): array
    {
        $fns = [];
        foreach (glob(self::$root . '/.filo/traces/*.json') ?: [] as $f) {
            foreach (json_decode((string) file_get_contents($f), true)['events'] ?? [] as $e) {
                $fns[] = $e['fn'];
            }
        }

        return $fns;
    }
}

beforeAll(function (): void {
    if (function_exists('opcache_get_status')) {
        OpcacheServer::start();
    }
});
afterAll(fn () => OpcacheServer::stop());

beforeEach(function (): void {
    if (!function_exists('opcache_get_status')) {
        $this->markTestSkipped('needs the opcache extension');
    }
});

test('a file cached by an untraced request is still traced once tracing is on', function (): void {
    file_put_contents(OpcacheServer::$root . '/a.php', "<?php\nfunction filo_oc_a(): int { return 1; }\n");

    OpcacheServer::trace(false);
    [$status, $body] = OpcacheServer::get('/?f=a');
    expect($status)->toBe(200)
        ->and($body['opcache'])->toBeTrue('opcache must be live in the server, or this test proves nothing')
        ->and($body['now_cached'])->toBeTrue('the untraced request must leave the original a.php in the opcache');

    OpcacheServer::trace(true);
    [$status, $body] = OpcacheServer::get('/?f=a');
    expect($status)->toBe(200, var_export($body, true))
        ->and(OpcacheServer::tracedFunctions())->toContain('filo_oc_a');
});

test('instrumented code does not outlive tracing in the opcache', function (): void {
    file_put_contents(OpcacheServer::$root . '/b.php', "<?php\nfunction filo_oc_b(): int { return 2; }\n");

    OpcacheServer::trace(true);
    [$status] = OpcacheServer::get('/?f=b'); // first compile of b.php is the instrumented one
    expect($status)->toBe(200);

    OpcacheServer::trace(false);
    [$status, $body] = OpcacheServer::get('/?f=b');
    expect($status)->toBe(200, var_export($body, true))
        ->and($body['result'])->toBe(2)
        ->and($body['collector_loaded'])->toBeFalse(); // true = instrumented b.php ran untraced
});

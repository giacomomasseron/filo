<?php

declare(strict_types=1);

/*
 * What does tracing cost? From the package root, after `composer install`:
 *
 *     php examples/bench.php
 *
 * Every workload runs in its own PHP process, with filo off and then on,
 * and opcache off both times: filo switches opcache off for each traced
 * request. It measures
 *
 *   - a function and a method called in a loop: what each traced call adds,
 *     the memory it holds until the request ends, and writing the trace
 *   - including 100 class files: the first time (filo instruments them),
 *     then from filo's cache
 *   - the check every request makes while tracing is off
 *
 * and prints a Markdown table, also appended to $GITHUB_STEP_SUMMARY when CI
 * sets it. The fixtures live in a temp dir: filo never instruments its own
 * package.
 */

const CALLS = 20_000; // per run, of each kind of call
const RUNS  = 5;      // medians of
const FILES = 100;

if (($argv[1] ?? '') === '--worker') {
    echo json_encode(worker($argv[2], $argv[3]));
    exit(0);
}

$root  = sys_get_temp_dir() . '/filo-bench-' . getmypid();
$lines = writeFixtures($root);
$env   = static fn (bool $on, string $cache): array => [
    'FILO_ENABLED'      => $on ? '1' : '0',
    'FILO_PROJECT_ROOT' => $root,
    'FILO_CACHE_DIR'    => $root . '/cache/' . $cache,
    'FILO_EXCLUDE'      => '/vendor/',
];

$callsOff = run('calls', $root, $env(false, 'calls'));
$callsOn  = run('calls', $root, $env(true, 'calls'));
$recorded = RUNS * 2 * (CALLS + 1); // the timed calls, plus each loop's own
($callsOn['traced'] && $callsOn['events'] >= $recorded && !$callsOn['capped'])
    || fail('filo did not record every call: ' . json_encode($callsOn));

$includes = ['off' => [], 'cold' => [], 'warm' => []];
for ($i = 0; $i < 3; $i++) {
    $includes['off'][]  = run('includes', $root, $env(false, "inc$i"))['ns'];
    $includes['cold'][] = run('includes', $root, $env(true, "inc$i"))['ns']; // empty cache
    $includes['warm'][] = run('includes', $root, $env(true, "inc$i"))['ns']; // filled by the run before
}

// Tracing off, as in an app: bootstrap.php and Tracer.php installed in its
// vendor/, no env vars, so filo finds the project root the way apps do.
$app = $root . '/app';
@mkdir($app . '/vendor/giacomomasseron/filo/src', 0777, true);
file_put_contents($app . '/composer.json', '{}');
copy(dirname(__DIR__) . '/bootstrap.php', $app . '/vendor/giacomomasseron/filo/bootstrap.php');
copy(dirname(__DIR__) . '/src/Tracer.php', $app . '/vendor/giacomomasseron/filo/src/Tracer.php');
$offCached = run('off', $app, ['FILO_ENABLED' => null, 'FILO_PROJECT_ROOT' => null], true);
$offPlain  = run('off', $app, ['FILO_ENABLED' => null, 'FILO_PROJECT_ROOT' => null], false);

$off  = median($includes['off']);
$cold = median($includes['cold']);
$warm = median($includes['warm']);
$rows = [
    [sprintf('A function call (%d × %s)', RUNS, number_format(CALLS)), duration($callsOff['fn_ns']), duration($callsOn['fn_ns']), added($callsOn['fn_ns'] - $callsOff['fn_ns']) . ' per call'],
    ['A method call (the same)', duration($callsOff['method_ns']), duration($callsOn['method_ns']), added($callsOn['method_ns'] - $callsOff['method_ns']) . ' per call'],
    ['Memory a recorded call holds until the request ends', '', '', sprintf('%d bytes', round($callsOn['bytes'] / $recorded))],
    [sprintf('Writing the trace (%s calls)', number_format($callsOn['events'])), '', duration($callsOn['flush_ns']), duration($callsOn['flush_ns'] / $callsOn['events']) . ' per call'],
    [sprintf('Including %d files of %d lines, the first time', FILES, $lines), duration($off), duration($cold), added(($cold - $off) / FILES) . ' per file'],
    ["The same files, from filo's cache", duration($off), duration($warm), added(($warm - $off) / FILES) . ' per file'],
    ['The check each request makes while tracing is off', '', '', ($offCached['opcache'] ? duration($offCached['check_ns']) . ' with opcache, ' : '') . duration($offPlain['first_ns']) . ' without'],
];

$md = "### filo overhead\n\n"
    . sprintf("PHP %s · %s %s%s · opcache off unless noted · medians, each workload in its own process\n\n", PHP_VERSION, PHP_OS_FAMILY, php_uname('m'), cpu())
    . "| Workload | filo off | filo on | Added by tracing |\n|---|--:|--:|---|\n";
foreach ($rows as $row) {
    $md .= '| ' . implode(' | ', $row) . " |\n";
}
echo $md;
$summary = getenv('GITHUB_STEP_SUMMARY');
if (is_string($summary) && $summary !== '') {
    file_put_contents($summary, $md . "\n", FILE_APPEND);
}
removeDir($root);

// ---- worker: one workload, in a fresh process ---------------------------

/** @return array<string, mixed> */
function worker(string $what, string $root): array
{
    $pkg = dirname(__DIR__);

    if ($what === 'off') {
        $boot = $root . '/vendor/giacomomasseron/filo/bootstrap.php'; // $root is the app here
        $t    = hrtime(true);
        require $boot; // compiles it and src/Tracer.php, unless opcache has them
        $first = hrtime(true) - $t;
        if (defined('FILO_BOOTSTRAPPED')) {
            return ['error' => 'tracing turned on in the tracing-off workload'];
        }
        $t = hrtime(true);
        for ($i = 0; $i < 2000; $i++) {
            clearstatcache(); // every request starts with an empty stat cache
            include $boot;
        }

        return [
            'first_ns' => $first,
            'check_ns' => (hrtime(true) - $t) / 2000,
            'opcache'  => function_exists('opcache_get_status') && (bool) (opcache_get_status(false)['opcache_enabled'] ?? false),
        ];
    }

    require $pkg . '/vendor/autoload.php'; // boots filo when it's on

    if ($what === 'includes') {
        $files = glob($root . '/src/lib/*.php') ?: [];
        $t     = hrtime(true);
        foreach ($files as $file) {
            require $file;
        }

        return ['ns' => hrtime(true) - $t, 'traced' => defined('FILO_BOOTSTRAPPED')];
    }

    require $root . '/src/calls.php';
    $bench = new FiloBench();
    filo_bench_calls(1000); // warm up
    $bench->calls(1000);
    $fn     = [];
    $method = [];
    $memory = memory_get_usage();
    for ($r = 0; $r < RUNS; $r++) {
        $t        = hrtime(true);
        filo_bench_calls(CALLS);
        $fn[]     = (hrtime(true) - $t) / CALLS;
        $t        = hrtime(true);
        $bench->calls(CALLS);
        $method[] = (hrtime(true) - $t) / CALLS;
    }
    $out = ['fn_ns' => median($fn), 'method_ns' => median($method), 'traced' => defined('FILO_BOOTSTRAPPED')];
    if ($out['traced']) {
        $out['bytes'] = memory_get_usage() - $memory;
        $t            = hrtime(true);
        \Filo\Collector::flush(\Filo\Tracer::$outputDir);
        $out['flush_ns'] = hrtime(true) - $t;
        $traces          = glob(\Filo\Tracer::$outputDir . '/*.json') ?: [];
        rsort($traces); // named down to the microsecond: newest first
        $json          = $traces === [] ? '' : (string) file_get_contents($traces[0]);
        $out['events'] = substr_count($json, '{"i":');
        $out['capped'] = str_contains(substr($json, 0, 200), '"capped":true');
    }

    return $out;
}

// ---- helpers -------------------------------------------------------------

/**
 * @param array<string, ?string> $env overrides; null removes the variable
 * @return array<string, mixed>
 */
function run(string $what, string $root, array $env, bool $opcache = false): array
{
    $vars = array_filter(array_merge(getenv(), $env), static fn (?string $v): bool => $v !== null);
    $proc = proc_open(
        [PHP_BINARY, '-d', 'opcache.enable_cli=' . (int) $opcache, '-d', 'memory_limit=1G', '-d', 'display_errors=stderr', __FILE__, '--worker', $what, $root],
        [1 => ['pipe', 'w'], 2 => STDERR],
        $pipes,
        null,
        $vars,
    );
    $proc !== false || fail("could not start the $what worker");
    $raw = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $code = proc_close($proc);
    $out  = json_decode($raw, true);
    ($code === 0 && is_array($out) && !isset($out['error']))
        || fail("the $what worker failed: " . (is_array($out) ? ($out['error'] ?? '') : trim($raw)));

    return $out;
}

/** Writes the fixture "app" under $root; returns the lines per class file. */
function writeFixtures(string $root): int
{
    @mkdir($root . '/src/lib', 0777, true);
    file_put_contents($root . '/composer.json', '{}');
    file_put_contents($root . '/src/calls.php', <<<'PHP'
        <?php
        function filo_bench_leaf(int $x): int { return $x + 1; }
        function filo_bench_calls(int $n): int { $s = 0; for ($i = 0; $i < $n; $i++) { $s = filo_bench_leaf($s); } return $s; }
        final class FiloBench
        {
            public function leaf(int $x): int { return $x + 1; }
            public function calls(int $n): int { $s = 0; for ($i = 0; $i < $n; $i++) { $s = $this->leaf($s); } return $s; }
        }
        PHP);

    $lines = 0;
    for ($f = 1; $f <= FILES; $f++) {
        $code = sprintf("<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Bench;\n\nfinal class C%03d\n{\n", $f);
        for ($m = 1; $m <= 20; $m++) {
            $code .= <<<PHP
                    public function m$m(int \$a, array \$b = []): int
                    {
                        \$x = \$a * $m;
                        foreach (\$b as \$v) {
                            \$x += \$v;
                        }

                        return \$x > 10 ? \$x - 1 : \$x + 1;
                    }

                PHP;
        }
        $code  .= "}\n";
        $lines += substr_count($code, "\n");
        file_put_contents(sprintf('%s/src/lib/C%03d.php', $root, $f), $code);
    }

    return intdiv($lines, FILES);
}

/** @param list<int|float> $xs */
function median(array $xs): float
{
    sort($xs);
    $n = count($xs);

    return $n % 2 === 1 ? (float) $xs[intdiv($n, 2)] : ($xs[$n / 2 - 1] + $xs[$n / 2]) / 2;
}

function duration(float $ns): string
{
    return match (true) {
        $ns < 1e3 => sprintf('%d ns', round($ns)),
        $ns < 1e6 => sprintf('%.2f µs', $ns / 1e3),
        default   => sprintf('%.1f ms', $ns / 1e6),
    };
}

function added(float $ns): string
{
    return ($ns < 0 ? '−' : '+') . duration(abs($ns));
}

function cpu(): string
{
    $info = @file_get_contents('/proc/cpuinfo');
    if (is_string($info) && preg_match('/^model name\s*:\s*(.+)$/m', $info, $m) === 1) {
        return ' · ' . trim($m[1]);
    }
    $brand = PHP_OS_FAMILY === 'Darwin' ? trim((string) @shell_exec('sysctl -n machdep.cpu.brand_string')) : '';

    return $brand === '' ? '' : ' · ' . $brand;
}

function removeDir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $all = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($all as $entry) {
        $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
    }
    @rmdir($dir);
}

function fail(string $message): never
{
    fwrite(STDERR, "bench: $message\n");
    exit(1);
}

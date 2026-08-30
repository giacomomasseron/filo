<?php

declare(strict_types=1);

/*
 * End-to-end smoke test. Run from the package root after `composer install`:
 *
 *     FILO_ENABLED=1 php -d opcache.enable_cli=0 examples/smoke.php
 *
 * Verifies: bootstrap fires, the stream wrapper intercepts an include,
 * hooks are injected, the collector links parent/child and measures time.
 *
 * The fixture is written to the system temp dir on purpose: the tracer
 * always excludes its own package directory from instrumentation, so a
 * fixture inside the repo would be skipped.
 */

require __DIR__ . '/../vendor/autoload.php';

if (!defined('FILO_BOOTSTRAPPED')) {
    fwrite(STDERR, "FAIL: tracer did not bootstrap. Run with FILO_ENABLED=1\n");
    exit(1);
}

$fixture = sys_get_temp_dir() . '/filo-smoke-' . getmypid() . '.php';
file_put_contents($fixture, <<<'PHP'
<?php
function filo_smoke_inner(): int {
    usleep(30_000);
    return 42;
}
function filo_smoke_outer(): int {
    usleep(10_000);
    return filo_smoke_inner();
}
PHP);

require $fixture;

$result = filo_smoke_outer();
@unlink($fixture);

\Filo\Collector::flush(\Filo\Tracer::$outputDir);

// ---- assertions on the freshest trace file ----------------------------

$files = glob(rtrim(\Filo\Tracer::$outputDir, '/') . '/*.json');
if ($files === false || $files === []) {
    fwrite(STDERR, "FAIL: no trace file written to " . \Filo\Tracer::$outputDir . "\n");
    exit(1);
}
usort($files, static fn ($a, $b) => filemtime($b) <=> filemtime($a));
$trace = json_decode((string) file_get_contents($files[0]), true);

$byFn = [];
foreach ($trace['events'] ?? [] as $ev) {
    $byFn[$ev['fn']] = $ev;
}

$fail = static function (string $msg): never {
    fwrite(STDERR, "FAIL: {$msg}\n");
    exit(1);
};

$result === 42
    || $fail('fixture returned wrong value — instrumented code changed behavior');
isset($byFn['filo_smoke_outer'], $byFn['filo_smoke_inner'])
    || $fail('expected events missing — wrapper did not intercept the include (check STREAM_OPEN_FOR_INCLUDE fires on this SAPI)');
$byFn['filo_smoke_inner']['p'] === $byFn['filo_smoke_outer']['i']
    || $fail('parent linkage wrong');

$innerMs = ($byFn['filo_smoke_inner']['e'] - $byFn['filo_smoke_inner']['s']) / 1e6;
$outerMs = ($byFn['filo_smoke_outer']['e'] - $byFn['filo_smoke_outer']['s']) / 1e6;

$innerMs >= 25 || $fail(sprintf('inner duration %.2fms, expected >= ~30ms', $innerMs));
$outerMs >= $innerMs || $fail('outer duration should include inner');

printf("PASS  trace: %s\n", $files[0]);
printf("PASS  filo_smoke_outer  %.2fms (self %.2fms)\n", $outerMs, $outerMs - $innerMs);
printf("PASS  filo_smoke_inner  %.2fms\n", $innerMs);

<?php

declare(strict_types=1);

/**
 * Runs tests/Fixtures/Threshold in a child `pest` process whose config gives
 * TraceExtension a `threshold` parameter, then reads each fixture's outcome
 * from the JUnit log. Runs are memoized: several tests read the same one.
 *
 * Fixtures: 5 Pest + 4 PHPUnit — over / under / raised / disabled limit on
 * both, plus a Pest test that runs over the limit AND fails on its own.
 *
 * @return array{0:int, 1:string, 2:array<string, ?string>} exit code, output,
 *         outcome per fixture keyed by its lowercased letters-only name
 *         (null = passed, otherwise the failure text)
 */
function runThresholdFixtures(bool $enabled, string $threshold): array
{
    static $runs = [];
    $key = ($enabled ? 'on' : 'off') . '|' . $threshold;
    if (isset($runs[$key])) {
        return $runs[$key];
    }

    $repo = str_replace('\\', '/', dirname(__DIR__, 2));
    $root = sys_get_temp_dir() . '/filo-threshold-' . getmypid() . '-' . bin2hex(random_bytes(2));
    mkdir($root, 0777, true);
    file_put_contents($root . '/composer.json', '{}'); // looksLikeProject()

    $config = $root . '/phpunit.xml';
    file_put_contents($config, <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="{$repo}/vendor/autoload.php" colors="false">
    <testsuites>
        <testsuite name="Threshold"><directory>{$repo}/tests/Fixtures/Threshold</directory></testsuite>
    </testsuites>
    <extensions>
        <bootstrap class="Filo\Testing\PHPUnit\TraceExtension">
            <parameter name="threshold" value="{$threshold}"/>
        </bootstrap>
    </extensions>
</phpunit>
XML);

    $env = array_merge(getenv(), [
        'FILO_PROJECT_ROOT' => $root,
        'FILO_ENABLED'      => $enabled ? '1' : '0',
    ]);
    $junit = $root . '/junit.xml';
    $cmd   = [PHP_BINARY, '-d', 'opcache.enable_cli=0', $repo . '/vendor/bin/pest', '--configuration', $config, '--log-junit', $junit, '--no-coverage'];
    $p     = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $repo, $env);
    $out   = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    $code  = proc_close($p);

    // Missing or unparsable log = the child died early; the assertions
    // then fail with $out, which says why.
    $log  = is_file($junit) ? trim((string) file_get_contents($junit)) : '';
    $prev = libxml_use_internal_errors(true);
    $xml  = $log === '' ? false : simplexml_load_string($log);
    libxml_use_internal_errors($prev);

    $outcomes = [];
    if ($xml !== false) {
        foreach ($xml->xpath('//testcase') ?: [] as $case) {
            $name            = strtolower((string) preg_replace('/[^a-z]/i', '', (string) $case['name']));
            // Every <failure> node: a second failure raised on an already
            // failed test only shows up as an extra node.
            $failures        = array_map('strval', $case->xpath('failure') ?: []);
            $outcomes[$name] = $failures === [] ? null : implode("\n", $failures);
        }
    }

    return $runs[$key] = [$code, $out, $outcomes];
}

/**
 * Failure texts of the fixtures whose name contains $needle (all failing
 * fixtures when $needle is '').
 *
 * @param array<string, ?string> $outcomes
 * @return array<string, string>
 */
function thresholdFailures(array $outcomes, string $needle = ''): array
{
    return array_filter(
        $outcomes,
        static fn (?string $failure, string $name): bool => $failure !== null && str_contains($name, $needle),
        ARRAY_FILTER_USE_BOTH,
    );
}

test('only the tests over their limit fail', function (): void {
    [, $out, $outcomes] = runThresholdFixtures(true, '100');

    expect($outcomes)->toHaveCount(9, $out)
        ->and(thresholdFailures($outcomes))->toHaveCount(3, $out)
        ->and(thresholdFailures($outcomes, 'overlimit'))->toHaveCount(2, $out)
        ->and(thresholdFailures($outcomes, 'failingonitsown'))->toHaveCount(1, $out);
});

test('the failure names the limit and the slowest function', function (): void {
    [, $out, $outcomes] = runThresholdFixtures(true, '100');

    $failures = thresholdFailures($outcomes, 'overlimit');
    expect($failures)->toHaveCount(2, $out);
    foreach ($failures as $failure) {
        expect($failure)
            ->toContain('filo threshold: took')
            ->toContain('limit 100 ms')
            ->toContain('slowest self-time: filo_threshold_nap');
    }
});

test('a test that already failed keeps its own failure', function (): void {
    [, $out, $outcomes] = runThresholdFixtures(true, '100');

    $failures = thresholdFailures($outcomes, 'failingonitsown');
    expect($failures)->toHaveCount(1, $out)
        ->and(array_values($failures)[0])
        ->toContain('Failed asserting that 1 is identical to 2')
        ->not->toContain('filo threshold');
});

test('without FILO_ENABLED the threshold is not enforced', function (): void {
    [, $out, $outcomes] = runThresholdFixtures(false, '100');

    expect($outcomes)->toHaveCount(9, $out)
        ->and(thresholdFailures($outcomes))->toHaveCount(1, $out)
        ->and(thresholdFailures($outcomes, 'failingonitsown'))->toHaveCount(1, $out);
});

test('an invalid threshold parameter is reported and not enforced', function (): void {
    [, $out, $outcomes] = runThresholdFixtures(true, '100ms');

    expect($out)->toContain("got '100ms'")
        ->and($outcomes)->toHaveCount(9, $out)
        ->and(thresholdFailures($outcomes))->toHaveCount(1, $out)
        ->and(thresholdFailures($outcomes, 'failingonitsown'))->toHaveCount(1, $out);
});

<?php

declare(strict_types=1);

use Filo\Testing\PHPUnit\TestArtifact;

test('fileName sanitises class, method and dataset', function (): void {
    expect(TestArtifact::fileName('App\Tests\FooTest', 'it_works', null))
        ->toBe('App_Tests_FooTest__it_works.json')
        ->and(TestArtifact::fileName('FooTest', 'bar', 'with spaces/slashes'))
        ->toBe('FooTest__bar#with-spaces-slashes.json');
});

test('write produces a v1 trace file under .filo/traces/tests', function (): void {
    $root = sys_get_temp_dir() . '/filo-artifact-' . getmypid();
    @mkdir($root, 0777, true);

    $path = TestArtifact::write($root, 'FooTest', 'bar', null, 'failed', [], 3_000_000);

    expect($path)->toBe($root . '/.filo/traces/tests/FooTest__bar.json')
        ->and(is_file($path))->toBeTrue();
    $t = json_decode((string) file_get_contents($path), true);
    expect($t['version'])->toBe(1)
        ->and($t['duration'])->toBe(3_000_000)
        ->and($t['context'])->toBe(['sapi' => PHP_SAPI, 'test' => 'FooTest::bar', 'status' => 'failed'])
        ->and($t['events'])->toBe([]);
});

test('write records the real capped flag', function (): void {
    $root = sys_get_temp_dir() . '/filo-artifact-capped-' . getmypid();
    @mkdir($root, 0777, true);

    $path = TestArtifact::write($root, 'FooTest', 'capped', null, 'traced', [], 1, true);

    expect(json_decode((string) file_get_contents($path), true)['capped'])->toBeTrue();
});

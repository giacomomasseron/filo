<?php

declare(strict_types=1);

use Filo\Testing\PHPUnit\TestArtifact;
use Filo\Testing\Recorder;
use Filo\Tests\Support\TempProject;
use JsonSchema\Validator;

/**
 * docs/trace-v1.schema.json is the contract for trace files and for the
 * viewer API that serves them. Every producer's output must validate.
 *
 * @return list<string> validation errors, empty when valid
 */
function traceSchemaErrors(string $json): array
{
    $schemaFile = dirname(__DIR__, 2) . '/docs/trace-v1.schema.json';
    expect($schemaFile)->toBeFile();

    $data      = json_decode($json);
    $validator = new Validator();
    $validator->validate($data, json_decode((string) file_get_contents($schemaFile)));

    return array_map(static fn (array $e): string => "{$e['property']}: {$e['message']}", $validator->getErrors());
}

function exampleTrace(): array
{
    return json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/examples/sample-trace.json'), true);
}

test('the sample trace in examples/ is valid', function (): void {
    expect(traceSchemaErrors(json_encode(exampleTrace())))->toBe([]);
});

test('a trace written at the end of a CLI run is valid', function (): void {
    $root = TempProject::root();
    file_put_contents($root . '/work.php', "<?php\nfunction filo_schema_a(): int { return filo_schema_b(); }\nfunction filo_schema_b(): int { return 1; }\n");
    file_put_contents($root . '/main.php', "<?php\nrequire " . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ";\nrequire __DIR__ . '/work.php';\nfilo_schema_a();\n");

    $env = array_merge(getenv(), ['FILO_ENABLED' => '1', 'FILO_PROJECT_ROOT' => $root, 'FILO_CACHE_DIR' => $root . '/cache']);
    $p   = proc_open([PHP_BINARY, '-d', 'opcache.enable_cli=0', $root . '/main.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    proc_close($p);

    $files = glob($root . '/.filo/traces/*.json') ?: [];
    expect($files)->toHaveCount(1, $out)
        ->and(traceSchemaErrors((string) file_get_contents($files[0])))->toBe([]);
});

test('a per-test artifact is valid', function (): void {
    $path = TestArtifact::write(TempProject::root(), 'App\FooTest', 'testBar', 'one', 'failed', [
        ['i' => 0, 'p' => -1, 'fn' => 'App\Foo::bar', 'file' => '/app/Foo.php', 'line' => 3, 's' => 10, 'e' => 90, 'm' => 1024],
    ], 100);

    expect(traceSchemaErrors((string) file_get_contents($path)))->toBe([]);
});

test('a Recorder capture exported with toJson() is valid', function (): void {
    if (!defined('FILO_BOOTSTRAPPED')) {
        $this->markTestSkipped('needs FILO_ENABLED=1');
    }
    require_once TempProject::fixture('SchemaCapture.php', "<?php\nfunction filo_schema_capture(): int { return 1; }\n");

    $trace = Recorder::capture(fn () => filo_schema_capture());

    expect($trace->calls('filo_schema_capture'))->toBe(1)
        ->and(traceSchemaErrors($trace->toJson()))->toBe([]);
});

test('the schema rejects an event without a function name', function (): void {
    $trace = exampleTrace();
    unset($trace['events'][0]['fn']);

    expect(traceSchemaErrors(json_encode($trace)))->not->toBe([]);
});

test('the schema rejects another format version', function (): void {
    $trace            = exampleTrace();
    $trace['version'] = 2;

    expect(traceSchemaErrors(json_encode($trace)))->not->toBe([]);
});

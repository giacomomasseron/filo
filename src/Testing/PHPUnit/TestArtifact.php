<?php

declare(strict_types=1);

namespace Filo\Testing\PHPUnit;

/**
 * Builds and writes the per-test trace file. Framework-free on purpose
 * (bootstrapped eagerly); TraceExtension is the only caller.
 */
final class TestArtifact
{
    public static function fileName(string $class, string $method, ?string $dataset): string
    {
        $name = str_replace('\\', '_', $class) . '__' . $method;
        if ($dataset !== null && $dataset !== '') {
            $name .= '#' . $dataset;
        }

        return preg_replace('/[^A-Za-z0-9_.#-]+/', '-', $name) . '.json';
    }

    /**
     * @param list<array{i:int,p:int,fn:string,file:string,line:int,s:int,e:int,m:int}> $events
     * @param 'failed'|'traced' $status
     * @return string written path
     */
    public static function write(
        string $projectRoot,
        string $class,
        string $method,
        ?string $dataset,
        string $status,
        array $events,
        int $wallNs,
    ): string {
        $dir = $projectRoot . '/.filo/traces/tests';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $path  = $dir . '/' . self::fileName($class, $method, $dataset);
        $trace = [
            'version'  => 1,
            'ts'       => date('c'),
            'duration' => $wallNs,
            'capped'   => false,
            'context'  => ['sapi' => 'cli', 'test' => $class . '::' . $method, 'status' => $status],
            'events'   => $events,
        ];
        file_put_contents($path, json_encode($trace, JSON_INVALID_UTF8_SUBSTITUTE));

        return $path;
    }
}

<?php

declare(strict_types=1);

namespace Filo\Tests\Support;

use Filo\Export\TraceFile;

/** Small traces with known numbers, for the exporter tests. */
final class SampleTraces
{
    /**
     * GET /orders, 1000 ns: Kernel::handle (0-1000) calls Repo::find twice
     * (100-300, and 400-600 running a closure 450-500), then View::render
     * (700-950). Self times: handle 350, find 200 + 150, closure 50, render 250.
     */
    public static function request(): TraceFile
    {
        return TraceFile::fromArray([
            'version'  => 1,
            'ts'       => '2026-09-15T10:00:00+00:00',
            'duration' => 1000,
            'capped'   => false,
            'context'  => ['sapi' => 'fpm-fcgi', 'method' => 'GET', 'uri' => '/orders'],
            'events'   => [
                self::event(0, -1, 'App\Http\Kernel::handle', '/app/Http/Kernel.php', 10, 0, 1000),
                self::event(1, 0, 'App\Repo::find', '/app/Repo.php', 20, 100, 300),
                self::event(2, 0, 'App\Repo::find', '/app/Repo.php', 20, 400, 600),
                self::event(3, 2, '{closure:App\Repo::find():22}', '/app/Repo.php', 22, 450, 500),
                self::event(4, 0, 'App\View::render', '/app/View.php', 30, 700, 950),
            ],
        ], '20260915-100000-000001-abcd.json');
    }

    /** @return array{i: int, p: int, fn: string, file: string, line: int, s: int, e: int, m: int} */
    public static function event(int $i, int $p, string $fn, string $file, int $line, int $s, int $e): array
    {
        return ['i' => $i, 'p' => $p, 'fn' => $fn, 'file' => $file, 'line' => $line, 's' => $s, 'e' => $e, 'm' => 0];
    }
}

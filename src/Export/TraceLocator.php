<?php

declare(strict_types=1);

namespace Filo\Export;

/**
 * Finds the trace `filo export` is asked for: "latest" (the newest request
 * trace), a file name in the traces folder, as the viewer lists it
 * ("20260915-101530-123456-ab12.json", "tests/App_FooTest__testBar.json"),
 * or a path.
 *
 * @internal
 */
final class TraceLocator
{
    private readonly string $dir;

    public function __construct(string $tracesDir)
    {
        $this->dir = rtrim($tracesDir, '/\\');
    }

    /** @throws ExportException when there is no such trace */
    public function locate(string $what): string
    {
        if ($what === 'latest') {
            $traces = glob($this->dir . '/*.json') ?: [];
            if ($traces === []) {
                throw ExportException::noTraces($this->dir);
            }
            rsort($traces); // named down to the microsecond: newest first

            return $traces[0];
        }
        foreach ([$this->dir . '/' . $what, $what] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        throw ExportException::traceNotFound($what, $this->dir);
    }

    /** $path's name as the viewer lists it when it's in the traces folder, else its file name. */
    public function nameOf(string $path): string
    {
        $prefix = strtr($this->dir . '/', '\\', '/');

        return str_starts_with(strtr($path, '\\', '/'), $prefix) ? substr($path, strlen($prefix)) : basename($path);
    }
}

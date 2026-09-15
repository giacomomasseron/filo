<?php

declare(strict_types=1);

namespace Filo\Export;

use RuntimeException;

/**
 * Why an export can't be made. The message is written for the user:
 * `filo export` prints it as is.
 *
 * @internal
 */
final class ExportException extends RuntimeException
{
    public static function unreadable(string $path): self
    {
        return new self("can't read {$path}");
    }

    public static function notATrace(string $name, string $why): self
    {
        return new self("{$name} is not a filo trace: {$why}");
    }

    /** @param list<string> $formats */
    public static function unknownFormat(string $format, array $formats): self
    {
        return new self(sprintf('unknown format "%s": use %s', $format, implode(' or ', $formats)));
    }

    public static function noTraces(string $dir): self
    {
        return new self("no request traces in {$dir} yet: turn tracing on (filo on) and make a request");
    }

    public static function traceNotFound(string $what, string $dir): self
    {
        return new self("no trace \"{$what}\": give a file name from {$dir} (e.g. tests/App_FooTest__testBar.json), a path, or latest");
    }

    public static function unwritable(string $path): self
    {
        return new self("can't write {$path}");
    }
}

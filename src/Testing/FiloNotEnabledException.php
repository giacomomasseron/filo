<?php

declare(strict_types=1);

namespace Filo\Testing;

/**
 * Thrown by call-based Trace queries when filo was not bootstrapped:
 * a silent "0 calls" pass must never happen.
 */
final class FiloNotEnabledException extends \RuntimeException
{
    public static function create(): self
    {
        return new self(
            'filo is not enabled in this process, so no calls were recorded. '
            . 'Run the suite with FILO_ENABLED=1 (e.g. `FILO_ENABLED=1 vendor/bin/pest`) '
            . 'or create a .filo-on marker file in the project root.',
        );
    }
}

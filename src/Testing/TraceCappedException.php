<?php

declare(strict_types=1);

namespace Filo\Testing;

/**
 * Thrown by call-based Trace queries when the collector hit its event cap:
 * events past the cap are dropped, so a "0 calls" pass would be a lie.
 */
final class TraceCappedException extends \RuntimeException
{
    public static function create(): self
    {
        return new self(
            'the collector hit its event cap (500000, or less under a tight memory_limit) during this run, so call counts '
            . 'are unreliable; reset per test via TraceExtension or reduce the captured scope',
        );
    }
}

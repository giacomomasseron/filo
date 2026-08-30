<?php

declare(strict_types=1);

namespace Filo\Testing;

use Closure;
use Filo\Collector;

/**
 * Scoped capture: run a closure, return the events it produced as a Trace.
 * Works whether or not filo is bootstrapped — when it isn't, the Trace
 * carries wall time only and call queries throw FiloNotEnabledException.
 */
final class Recorder
{
    public static function enabled(): bool
    {
        return \defined('FILO_BOOTSTRAPPED');
    }

    public static function capture(Closure $fn): Trace
    {
        $enabled = self::enabled();
        $mark    = $enabled ? Collector::mark() : 0;
        $start   = hrtime(true);
        $events  = [];
        $result  = null;

        try {
            $result = $fn();
        } finally {
            $wall = hrtime(true) - $start;
            if ($enabled) {
                $events = Collector::since($mark);
            }
        }

        return new Trace($events, $wall, $result, $enabled);
    }
}

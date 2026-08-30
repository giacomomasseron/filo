<?php

declare(strict_types=1);

namespace Filo\Testing;

/**
 * Shared assertion core for the PHPUnit trait and the Pest expectations.
 * Messages always name the offender so a red build tells you where to look.
 */
final class Assert
{
    public static function runsUnder(Trace $t, float $ms): void
    {
        $wall = $t->wallMs();
        if ($wall < $ms) {
            return;
        }

        $tail = '';
        if ($t->enabled()) {
            $parts = [];
            foreach ($t->slowestSelf(3) as $row) {
                $parts[] = sprintf('%s %s ms ×%d', $row['fn'], self::fmt($row['selfMs']), $row['calls']);
            }
            if ($parts !== []) {
                $tail = ' (slowest self-time: ' . implode(', ', $parts) . ')';
            }
        }

        throw new ExpectationFailed(sprintf('took %s ms, limit %s ms%s', self::fmt($wall), self::fmt($ms), $tail));
    }

    public static function callCount(Trace $t, string $fn, ?int $atLeast = null, ?int $atMost = null): void
    {
        $n = $t->calls($fn);

        if ($atLeast !== null && $atMost !== null && $atLeast === $atMost && $n !== $atLeast) {
            throw new ExpectationFailed(sprintf('%s called %d times, expected exactly %d', $fn, $n, $atLeast));
        }
        if ($atLeast !== null && $n < $atLeast) {
            throw new ExpectationFailed(sprintf('%s called %d times, expected at least %d', $fn, $n, $atLeast));
        }
        if ($atMost !== null && $n > $atMost) {
            throw new ExpectationFailed(sprintf('%s called %d times, expected at most %d', $fn, $n, $atMost));
        }
    }

    public static function noCalls(Trace $t, string $fn): void
    {
        $n = $t->calls($fn);
        if ($n > 0) {
            throw new ExpectationFailed(sprintf('%s called %d times, expected no calls', $fn, $n));
        }
    }

    /** 14.2, 10, 9.8 — one decimal, trailing .0 dropped. */
    private static function fmt(float $ms): string
    {
        return rtrim(rtrim(number_format($ms, 1, '.', ''), '0'), '.');
    }
}

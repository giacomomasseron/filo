<?php

declare(strict_types=1);

namespace Filo;

/**
 * Runtime hot path. enter()/leave() are called for EVERY instrumented
 * function call, so: static properties, flat arrays, no objects, no
 * allocations beyond the event row itself.
 */
final class Collector
{
    private const MAX_EVENTS = 500_000; // hard cap: runaway loops can't eat all memory

    /** @var array<int, array{i:int,p:int,fn:string,file:string,line:int,s:int,e:int,m:int}> */
    private static array $events = [];

    /** @var int[] stack of open event ids */
    private static array $stack = [];

    private static int $nextId = 0;
    private static int $t0     = 0;
    private static bool $capped = false;

    public static function begin(): void
    {
        self::$events = [];
        self::$stack  = [];
        self::$nextId = 0;
        self::$capped = false;
        self::$t0     = hrtime(true);
    }

    /**
     * @return int event id, passed back to leave() by the injected finally
     */
    public static function enter(string $fn, string $file, int $line): int
    {
        if (self::$capped) {
            return -1;
        }

        $id = self::$nextId++;

        if ($id >= self::MAX_EVENTS) {
            self::$capped = true;

            return -1;
        }

        self::$events[$id] = [
            'i'    => $id,
            'p'    => self::$stack === [] ? -1 : self::$stack[array_key_last(self::$stack)],
            'fn'   => $fn,
            'file' => $file,
            'line' => $line,
            's'    => hrtime(true) - self::$t0,
            'e'    => -1,
            'm'    => memory_get_usage(),
        ];
        self::$stack[] = $id;

        return $id;
    }

    public static function leave(int $id): void
    {
        if ($id < 0 || !isset(self::$events[$id])) {
            return;
        }

        self::$events[$id]['e'] = hrtime(true) - self::$t0;

        /*
         * Normally $id is exactly the top of the stack (finally is LIFO).
         * Generators destroyed out of order can violate that, so pop
         * defensively down to $id and close any skipped frames at the
         * same timestamp rather than leaving them dangling.
         */
        while (self::$stack !== []) {
            $top = array_pop(self::$stack);
            if ($top === $id) {
                break;
            }
            if (self::$events[$top]['e'] === -1) {
                self::$events[$top]['e'] = self::$events[$id]['e'];
            }
        }
    }

    /**
     * Called by Debugger after a breakpoint pause. Shifting the epoch
     * forward makes every SUBSEQUENT timestamp smaller by the paused
     * duration: the paused frame and all open ancestors shrink by
     * exactly the pause, while already-closed events are untouched.
     * Net effect: breakpoints leave no mark on the timeline.
     */
    public static function excludePause(int $ns): void
    {
        self::$t0 += $ns;
    }

    /**
     * Position marker for scoped captures (Filo\Testing\Recorder).
     * Not on the hot path.
     */
    public static function mark(): int
    {
        return self::$nextId;
    }

    /**
     * Nanoseconds since the collector epoch — the same clock the event
     * timestamps use, so excludePause() shifts it too and paused time
     * never lands in a measured duration. Not on the hot path.
     */
    public static function now(): int
    {
        return hrtime(true) - self::$t0;
    }

    /**
     * True once the event cap was hit: events past it were dropped, so
     * call counts are no longer trustworthy. Not on the hot path.
     */
    public static function capped(): bool
    {
        return self::$capped;
    }

    /**
     * Events recorded since mark(), as a self-contained forest: frames
     * still open are closed at "now" and parents that predate the mark
     * become roots (-1). The collector itself is not mutated.
     *
     * @return list<array{i:int,p:int,fn:string,file:string,line:int,s:int,e:int,m:int}>
     */
    public static function since(int $mark): array
    {
        if ($mark >= self::$nextId) {
            return [];
        }

        $now = hrtime(true) - self::$t0;
        $out = [];
        for ($i = max(0, $mark); $i < self::$nextId; $i++) {
            if (!isset(self::$events[$i])) {
                continue; // capped
            }
            $row = self::$events[$i];
            if ($row['e'] === -1) {
                $row['e'] = $now;
            }
            if ($row['p'] < $mark) {
                $row['p'] = -1;
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * For long-running runtimes (Octane, RoadRunner, FrankenPHP worker):
     * call at the end of each request instead of relying on shutdown.
     */
    public static function cycle(string $outputDir): void
    {
        self::flush($outputDir);
        self::begin();
        Debugger::cycle();
    }

    public static function flush(string $outputDir): void
    {
        if (self::$events === []) {
            return;
        }

        // Close anything still open (e.g. exit() mid-request).
        $now = hrtime(true) - self::$t0;
        foreach (self::$stack as $id) {
            if (self::$events[$id]['e'] === -1) {
                self::$events[$id]['e'] = $now;
            }
        }
        self::$stack = [];

        $isCli = PHP_SAPI === 'cli';

        $trace = [
            'version'  => 1,
            'ts'       => date('c'),
            'duration' => $now,                       // nanoseconds
            'capped'   => self::$capped,
            'context'  => $isCli
                ? ['sapi' => 'cli', 'argv' => $_SERVER['argv'] ?? []]
                : [
                    'sapi'   => PHP_SAPI,
                    'method' => $_SERVER['REQUEST_METHOD'] ?? null,
                    'uri'    => $_SERVER['REQUEST_URI'] ?? null,
                ],
            'events'   => array_values(self::$events),
        ];

        $name = sprintf('%s-%s.json', date('Ymd-His'), bin2hex(random_bytes(4)));

        @file_put_contents(
            rtrim($outputDir, '/') . '/' . $name,
            json_encode($trace, JSON_INVALID_UTF8_SUBSTITUTE),
        );

        self::$events = [];
    }
}

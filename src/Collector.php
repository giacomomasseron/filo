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
    /** Absolute ceiling on recorded events, whatever memory_limit allows. */
    private const MAX_EVENTS = 500_000;

    /**
     * What one recorded event costs: its row (~420 B measured) plus its
     * share of the JSON built at flush (~150 B), rounded up.
     */
    private const BYTES_PER_EVENT = 600;

    /** Events may use at most this fraction of memory_limit. */
    private const MEMORY_SHARE = 0.25;

    /** Stop recording once the whole process uses this share of memory_limit. */
    private const MEMORY_CEILING = 0.9;

    /** enter() checks the caps when ($id & mask) === 0, i.e. every 1024 events. */
    private const CAP_CHECK_MASK = 1023;

    /** Per-run caps, derived from memory_limit by begin(). */
    private static int $maxEvents     = self::MAX_EVENTS;
    private static int $memoryCeiling = PHP_INT_MAX;

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

        /*
         * Fail open: recording must never be what exhausts memory_limit (a
         * 500k-event trace is ~200 MB of rows, more than a default 128M).
         * Events get at most a quarter of the limit (128M -> ~55k events),
         * and nothing more is recorded once the process — app included —
         * passes 90% of it. Unlimited -> MAX_EVENTS only.
         */
        $limit = self::iniBytes((string) ini_get('memory_limit'));
        self::$maxEvents     = $limit > 0
            ? (int) min(self::MAX_EVENTS, $limit * self::MEMORY_SHARE / self::BYTES_PER_EVENT)
            : self::MAX_EVENTS;
        self::$memoryCeiling = $limit > 0 ? (int) ($limit * self::MEMORY_CEILING) : PHP_INT_MAX;
    }

    /** php.ini quantity ("128M", "1G", "-1") to bytes; <= 0 means unlimited. */
    private static function iniBytes(string $value): int
    {
        $value = trim($value);
        $n     = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g'     => $n * 1024 ** 3,
            'm'     => $n * 1024 ** 2,
            'k'     => $n * 1024,
            default => $n,
        };
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

        // Caps are checked once every 1024 events: at most ~400 KB of
        // overshoot, and no static reads on the other 1023 calls.
        if (($id & self::CAP_CHECK_MASK) === 0 && self::overCap($id)) {
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

    /** Event budget used up, or the process (app included) near memory_limit. */
    private static function overCap(int $id): bool
    {
        return $id >= self::$maxEvents || memory_get_usage() > self::$memoryCeiling;
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
     * Called by Debugger after a breakpoint pause, and by
     * IncludeStreamWrapper after instrumenting a file on a cache miss.
     * Shifting the epoch forward makes every SUBSEQUENT timestamp smaller
     * by the excluded duration: the current frame and all open ancestors
     * shrink by exactly that amount, while already-closed events are
     * untouched. Net effect: neither breakpoints nor filo's own parsing
     * leave a mark on the timeline.
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

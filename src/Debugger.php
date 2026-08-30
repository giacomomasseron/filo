<?php

declare(strict_types=1);

namespace Filo;

/**
 * Function-entry breakpoints, zero-infrastructure edition.
 *
 * How it works:
 *  - Breakpoints live in <project>/.filo/breakpoints.json — a plain list
 *    of names matching what __METHOD__ yields at runtime
 *    ("App\Service\Foo::bar", "my_function"). Edited by `bin/filo`
 *    or the future web UI. Reloaded per request (init() / cycle()).
 *  - The instrumented code evaluates `Debugger::$armed && Debugger::hit(__METHOD__)`
 *    at every function entry — a single static property read when
 *    disarmed; the call only happens when breakpoints exist.
 *  - On a hit, pause() writes a snapshot JSON (function, location,
 *    exported locals) into <output>/breaks/<id>.json and then POLLS
 *    for <id>.continue (or a global continue-all). The request is
 *    genuinely frozen mid-execution; you inspect, then release it.
 *  - Every pause auto-continues after FILO_BREAK_TIMEOUT seconds
 *    (default 120) so a forgotten breakpoint can never hang a request
 *    forever.
 *
 * Deliberate scope (v2): entry-only breakpoints, once per breakpoint
 * per request, inspect-and-continue — no stepping, no eval. That's the
 * honest boundary of userland instrumentation; people who need
 * engine-level stepping have Xdebug.
 */
final class Debugger
{
    /** Checked first in the injected hook — keep it a plain public static. */
    public static bool $armed = false;

    /** @var array<string, true> breakpoint names as hash-set */
    private static array $breakpoints = [];

    /** @var array<string, true> breakpoints already hit this request */
    private static array $hits = [];

    private static string $projectRoot = '';
    private static string $breaksDir   = '';
    private static int $timeoutSec     = 120;

    public static function init(string $projectRoot, string $breaksDir, int $timeoutSec): void
    {
        self::$projectRoot = $projectRoot;
        self::$breaksDir   = $breaksDir;
        self::$timeoutSec  = max(1, $timeoutSec);

        self::cycle();
    }

    /**
     * Per-request reset for long-running runtimes: clears "already hit"
     * state and reloads breakpoints.json. Called by Collector::cycle().
     */
    public static function cycle(): void
    {
        self::$hits = [];

        $file = self::$projectRoot . '/.filo/breakpoints.json';
        if (!is_file($file)) {
            self::$armed = false;

            return;
        }

        $config = json_decode((string) @file_get_contents($file), true);
        $names  = $config['breakpoints'] ?? [];

        // Entries are either "Class::method" strings (bin/filo) or objects
        // {id, fn, enabled} written by the web UI. Only enabled `fn`
        // entries arm; {file, line} entries can't fire in an entry-only
        // debugger and are ignored here (the UI still lists them).
        self::$breakpoints = [];
        foreach ((array) $names as $entry) {
            if (is_array($entry)) {
                if (($entry['enabled'] ?? true) === false) {
                    continue;
                }
                $entry = $entry['fn'] ?? null;
            }
            if (is_string($entry) && $entry !== '') {
                self::$breakpoints[$entry] = true;
            }
        }

        self::$armed = self::$breakpoints !== [];

        if (self::$armed && !is_dir(self::$breaksDir)) {
            @mkdir(self::$breaksDir, 0777, true);
        }
    }

    /** Called at instrumented function entry when $armed — must stay trivial. */
    public static function hit(string $fn): bool
    {
        return isset(self::$breakpoints[$fn]) && !isset(self::$hits[$fn]);
    }

    /**
     * Freeze this request: write the snapshot, poll for release.
     *
     * @param array<string, mixed> $vars from get_defined_vars() (+ __this)
     */
    public static function pause(string $fn, array $vars, string $file, int $line): void
    {
        self::$hits[$fn] = true;

        // Everything from here on — snapshot export included — is
        // excluded from the trace timeline (see the finally-like tail).
        $start = hrtime(true);

        $id         = date('His') . '-' . bin2hex(random_bytes(3));
        $snapshot   = self::$breaksDir . '/' . $id . '.json';
        $release    = self::$breaksDir . '/' . $id . '.continue';
        $releaseAll = self::$breaksDir . '/continue-all';

        // continue-all is a token file: CLI / server write a fresh unique
        // value each time. We only honor a token that differs from the one
        // present when this pause began — no mtime slack window.
        clearstatcache(true, $releaseAll);
        $seenToken = @file_exists($releaseAll) ? (string) @file_get_contents($releaseAll) : '';

        $written = @file_put_contents($snapshot, json_encode([
            'id'    => $id,
            'fn'    => $fn,
            'file'  => $file,
            'line'  => $line,
            'ts'    => date('c'),
            'pid'   => getmypid(),
            'uri'   => $_SERVER['REQUEST_URI'] ?? implode(' ', $_SERVER['argv'] ?? []),
            'vars'  => VarExporter::snapshot($vars),
        ], JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE));

        // Fail open: no snapshot means nobody can see or release this
        // pause, so don't freeze the request at all.
        if ($written === false) {
            Collector::excludePause(hrtime(true) - $start);

            return;
        }

        // Don't let max_execution_time kill the frozen request. (On Linux,
        // sleep barely counts against it anyway — it's CPU time — but
        // Windows counts wall clock.)
        @set_time_limit(self::$timeoutSec + 30);

        $deadlineNs = self::$timeoutSec * 1_000_000_000;

        while ((hrtime(true) - $start) < $deadlineNs) {
            clearstatcache(true, $release);

            if (@file_exists($release)) {
                @unlink($release);
                break;
            }

            // continue-all releases every currently paused request.
            clearstatcache(true, $releaseAll);
            if (@file_exists($releaseAll) && (string) @file_get_contents($releaseAll) !== $seenToken) {
                break;
            }

            usleep(150_000);
        }

        @unlink($snapshot);

        // Erase the pause from the trace timeline so breakpoints don't
        // pollute timings of this frame and every open ancestor.
        Collector::excludePause(hrtime(true) - $start);
    }
}

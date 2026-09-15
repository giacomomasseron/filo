<?php

declare(strict_types=1);

namespace Filo;

/**
 * Function-entry breakpoints, zero-infrastructure edition.
 *
 * How it works:
 *  - Breakpoints live in <project>/.filo/breakpoints.json, read through
 *    Filo\Breakpoints (shared with `bin/filo` and the viewer API) and
 *    reloaded per request (init() / cycle()). One names a function as
 *    __METHOD__ yields it at runtime ("App\Service\Foo::bar",
 *    "my_function", "{closure:...}"), or a file and line: that one fires
 *    at the entry of the innermost function containing the line.
 *  - The instrumented code evaluates `Debugger::$armed && Debugger::hit(...)`
 *    at every function entry — a single static property read when
 *    disarmed; the call only happens when breakpoints exist. hit() gets
 *    the function's name, file and line ranges (see HookVisitor).
 *  - On a hit, pause() writes a snapshot JSON (function, location,
 *    exported locals) into <output>/breaks/<id>.json and then POLLS
 *    for <id>.continue (or a global continue-all). The request is
 *    genuinely frozen mid-execution; you inspect, then release it.
 *  - Every pause auto-continues after FILO_BREAK_TIMEOUT seconds
 *    (default 120) so a forgotten breakpoint can never hang a request
 *    forever.
 *
 * Deliberate scope: entry-only breakpoints, once per breakpoint per
 * request, inspect-and-continue — no stepping, no eval. That's the
 * honest boundary of userland instrumentation; people who need
 * engine-level stepping have Xdebug.
 *
 * @internal Not part of the public API (README "Public API").
 */
final class Debugger
{
    /** Checked first in the injected hook — keep it a plain public static. */
    public static bool $armed = false;

    /** @var array<string, true> function breakpoints as hash-set */
    private static array $breakpoints = [];

    /** @var array<string, array<string, int>> file breakpoints: Breakpoints::fileKey() => [hit key => line] */
    private static array $lines = [];

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
        self::$hits        = [];
        self::$breakpoints = [];
        self::$lines       = [];
        foreach (Breakpoints::read(Breakpoints::file(self::$projectRoot)) as $bp) {
            if (!$bp['enabled']) {
                continue;
            }
            if (isset($bp['fn'])) {
                self::$breakpoints[$bp['fn']] = true;
            } else {
                $file                                          = Breakpoints::fileKey($bp['file'], self::$projectRoot);
                self::$lines[$file][$file . ':' . $bp['line']] = $bp['line'];
            }
        }

        self::$armed = self::$breakpoints !== [] || self::$lines !== [];

        if (self::$armed && !is_dir(self::$breaksDir)) {
            @mkdir(self::$breaksDir, 0777, true);
        }
    }

    /**
     * Called at instrumented function entry when $armed — must stay cheap.
     * True when a breakpoint that hasn't fired this request matches: one on
     * $fn, or one on a line of $file from $start to $end that none of the
     * $nested functions (hooked on their own) contains, i.e. a line this is
     * the innermost function of. Every match counts as hit, so an entry
     * pauses once.
     *
     * @param list<array{int, int}> $nested
     */
    public static function hit(string $fn, string $file = '', int $start = 0, int $end = 0, array $nested = []): bool
    {
        $hit = isset(self::$breakpoints[$fn]) && !isset(self::$hits[$fn]);
        if ($hit) {
            self::$hits[$fn] = true;
        }
        foreach (self::$lines[$file] ?? [] as $key => $line) {
            if ($line < $start || $line > $end || isset(self::$hits[$key])) {
                continue;
            }
            foreach ($nested as [$from, $to]) {
                if ($line >= $from && $line <= $to) {
                    continue 2;
                }
            }
            self::$hits[$key] = true;
            $hit              = true;
        }

        return $hit;
    }

    /**
     * Freeze this request: write the snapshot, poll for release.
     *
     * @param array<string, mixed> $vars      from get_defined_vars() (+ __this)
     * @param list<string>         $sensitive #[\SensitiveParameter] names, never exported
     */
    public static function pause(string $fn, array $vars, string $file, int $line, array $sensitive = []): void
    {
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
            'vars'  => VarExporter::snapshot($vars, $sensitive),
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

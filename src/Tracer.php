<?php

declare(strict_types=1);

namespace Filo;

/**
 * Orchestrator. Reads config from the environment (framework-agnostic:
 * no container, no framework config system) and wires everything up.
 *
 * Env vars:
 *   FILO_ENABLED=1                     master switch (checked in bootstrap.php)
 *   FILO_CACHE_DIR=/tmp/filo-cache     instrumented-file cache
 *   (traces are ALWAYS written to <project>/.filo/traces — not configurable)
 *   FILO_PROJECT_ROOT=/app             override project-root discovery (see findProjectRoot)
 * and the settings filo.json can hold too (see Settings: env > filo.json > default):
 *   FILO_INCLUDE=vendor/acme/billing   comma-separated paths to trace even though excluded
 *   FILO_EXCLUDE=/vendor/,/storage/    comma-separated path substrings to skip
 *   FILO_KEEP=200                      request traces to keep (0 = all)
 *   FILO_BREAK_TIMEOUT=120             seconds before a paused breakpoint auto-continues
 *
 * Public API: cycle(). Everything else here is @internal plumbing shared
 * by bootstrap.php, bin/filo and the viewer.
 */
final class Tracer
{
    private static bool $started      = false;
    private static bool $suppressFlush = false;

    /** Request traces to keep in .filo/traces (0 = all); see prune(). */
    private static int $keep = 0;

    /** @internal */
    public static string $cacheDir;
    /** @internal */
    public static string $outputDir;
    /** @internal */
    public static string $breaksDir;
    /** @internal */
    public static string $projectRoot;

    /**
     * What gets instrumented, as traces() takes it: paths to trace (a
     * folder ends with /), path substrings to skip, and substrings no
     * setting overrides. Spelled like Breakpoints::pathKey().
     *
     * @internal
     * @var list<string>
     */
    public static array $include = [];

    /**
     * @internal
     * @var list<string>
     */
    public static array $exclude = [];

    /**
     * @internal
     * @var list<string>
     */
    public static array $never = [];

    /**
     * The per-request boundary for long-running runtimes (Octane,
     * RoadRunner, FrankenPHP worker mode): writes the trace so far to
     * .filo/traces, starts a fresh one and reloads breakpoints. Call it
     * when a request ends. A no-op when tracing is off, so it can stay
     * wired in permanently.
     */
    public static function cycle(): void
    {
        if (self::$started) {
            Collector::cycle(self::$outputDir);
            self::prune();
        }
    }

    /**
     * Whether the file at $path gets instrumented: never when a $never
     * substring matches (filo itself), yes when an $include path does, no
     * when an $exclude substring does, yes otherwise. The lists are spelled
     * like Breakpoints::pathKey(), see pathRules().
     *
     * @internal
     * @param list<string> $include
     * @param list<string> $exclude
     * @param list<string> $never
     */
    public static function traces(string $path, array $include, array $exclude, array $never): bool
    {
        $key = Breakpoints::pathKey($path);
        foreach ($never as $needle) {
            if (str_contains($key, $needle)) {
                return false;
            }
        }
        foreach ($include as $entry) {
            if ($key === $entry || (str_ends_with($entry, '/') && str_starts_with($key, $entry))) {
                return true;
            }
        }
        foreach ($exclude as $needle) {
            if (str_contains($key, $needle)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The include and exclude settings the way traces() compares them:
     * include entries resolved against the project root (or absolute) and
     * made real, a folder with a trailing /, and dropped when they match
     * nothing (Settings reports those); exclude substrings pathKey()'d.
     *
     * @internal
     * @param list<string> $include
     * @param list<string> $exclude
     * @return array{list<string>, list<string>}
     */
    public static function pathRules(array $include, array $exclude, string $projectRoot): array
    {
        $paths = [];
        foreach ($include as $entry) {
            $real = realpath(self::resolve($entry, $projectRoot));
            if ($real !== false) {
                $paths[] = Breakpoints::pathKey($real) . (is_dir($real) ? '/' : '');
            }
        }
        $needles = [];
        foreach ($exclude as $needle) {
            if ($needle !== '') {
                $needles[] = Breakpoints::pathKey($needle);
            }
        }

        return [$paths, $needles];
    }

    /** @internal $path as it names a file: absolute as is, else relative to the project root. */
    public static function resolve(string $path, string $projectRoot): string
    {
        return preg_match('~^([A-Za-z]:)?[/\\\\]~', $path) === 1 ? $path : rtrim($projectRoot, '/\\') . '/' . $path;
    }

    /** @internal Called once, by bootstrap.php. */
    public static function start(): void
    {
        if (self::$started) {
            return;
        }
        self::$started = true;

        // Opcache stores whatever the compiler was handed. Left on, a traced
        // request runs opcodes an untraced one cached (bypassing the wrapper)
        // and caches its instrumented code for untraced requests to run.
        // opcache.enable can be switched off (never on) at runtime, and only
        // until this request ends; untraced requests keep the cache.
        ini_set('opcache.enable', '0');

        self::$projectRoot = self::findProjectRoot();
        self::$cacheDir    = self::env('FILO_CACHE_DIR', sys_get_temp_dir() . '/filo-cache');
        self::$outputDir   = self::outputDir(self::$projectRoot);
        self::$breaksDir   = self::$outputDir . '/breaks';

        $settings   = Settings::load(self::$projectRoot);
        self::$keep = $settings['keep'];

        if (!is_dir(self::$cacheDir)) {
            @mkdir(self::$cacheDir, 0777, true);
        }
        if (!is_dir(self::$outputDir)) {
            @mkdir(self::$outputDir, 0777, true);
        }

        [self::$include, self::$exclude] = self::pathRules($settings['include'], $settings['exclude'], self::$projectRoot);
        // Never instrument ourselves, our caches or the parser we instrument
        // with, whatever the settings say.
        self::$never = [
            Breakpoints::pathKey(dirname(__DIR__)) . '/', // this package
            Breakpoints::pathKey(realpath(self::$cacheDir) ?: self::$cacheDir) . '/',
            '/nikic/php-parser/',
        ];

        Collector::begin();
        Debugger::init(self::$projectRoot, self::$breaksDir, $settings['breakTimeout']);

        // Works for FPM, CLI and the built-in server. Long-running runtimes
        // (Octane, RoadRunner, FrankenPHP worker mode) should instead call
        // Tracer::cycle() at their per-request boundary — see README.
        register_shutdown_function(static function (): void {
            if (!self::$suppressFlush) {
                Collector::flush(self::$outputDir);
                self::prune();
            }
        });

        IncludeStreamWrapper::register();
    }

    /**
     * Test runners write one trace per test (Filo\Testing\PHPUnit\TraceExtension)
     * and must not also get a giant process-wide trace at exit.
     *
     * @internal
     */
    public static function suppressShutdownFlush(): void
    {
        self::$suppressFlush = true;
    }

    /**
     * THE single project-root rule — bootstrap.php, bin/filo and
     * server/index.php all defer to this so `.filo-on` and
     * `.filo/breakpoints.json` resolve to the same directory everywhere.
     *
     * Order: explicit FILO_PROJECT_ROOT env; the installed layout
     * (<root>/vendor/giacomomasseron/filo/src -> 4 up) only if it looks
     * like a project (has .filo/ or composer.json); then walk UP from the
     * running script's directory and from getcwd() until a directory
     * looks like a project; else cwd.
     *
     * The walk-up matters for two common layouts: `artisan serve` (and
     * most web servers) run PHP with cwd = public/, and a Composer
     * path-repository symlink makes __DIR__ resolve outside the project,
     * so neither fixed candidate hits the real root.
     *
     * @internal
     */
    public static function findProjectRoot(): string
    {
        return self::locateProjectRoot()[0];
    }

    /**
     * findProjectRoot(), plus how the root was found (for `filo doctor`).
     *
     * @internal
     * @return array{string, string} [root, how it was found]
     */
    public static function locateProjectRoot(): array
    {
        $env = self::env('FILO_PROJECT_ROOT', '');
        if ($env !== '') {
            return [rtrim($env, '/'), 'FILO_PROJECT_ROOT'];
        }

        $installed = dirname(__DIR__, 4);
        if (self::looksLikeProject($installed)) {
            return [$installed, "filo's place in vendor/"];
        }

        $script = (string) ($_SERVER['SCRIPT_FILENAME'] ?? '');
        $starts = array_filter([
            'the running script'    => $script !== '' ? dirname($script) : '',
            'the working directory' => (string) getcwd(),
        ]);

        foreach ($starts as $from => $dir) {
            while ($dir !== '.') {
                if (self::looksLikeProject($dir)) {
                    return [$dir, "walking up from $from"];
                }
                $parent = dirname($dir);
                if ($parent === $dir) {
                    break;
                }
                $dir = $parent;
            }
        }

        return [(string) getcwd(), 'nothing: no composer.json or .filo/ above the working directory'];
    }

    /**
     * Traces always live inside the project: <root>/.filo/traces
     * (breaks/ beneath it). Shared by Tracer, bin/filo and the viewer so
     * they can never disagree about where the JSON is.
     *
     * @internal
     */
    public static function outputDir(?string $projectRoot = null): string
    {
        return ($projectRoot ?? self::findProjectRoot()) . '/.filo/traces';
    }

    /**
     * Keeps the newest self::$keep request traces (0 = all). Per-test
     * artifacts in tests/ are left alone: there is one per test at most.
     */
    private static function prune(): void
    {
        $files = self::$keep > 0 ? (glob(self::$outputDir . '/*.json') ?: []) : [];
        if (count($files) <= self::$keep) {
            return;
        }
        $mtime = [];
        foreach ($files as $file) {
            $mtime[$file] = (int) @filemtime($file);
        }
        // Newest first: by mtime, then by name, which is down to the microsecond.
        usort($files, static fn (string $a, string $b): int => [$mtime[$b], $b] <=> [$mtime[$a], $a]);
        foreach (array_slice($files, self::$keep) as $old) {
            @unlink($old);
        }
    }

    private static function looksLikeProject(string $dir): bool
    {
        return $dir !== '' && (is_dir($dir . '/.filo') || is_file($dir . '/composer.json'));
    }

    private static function env(string $key, string $default): string
    {
        $value = $_SERVER[$key] ?? getenv($key);

        return ($value === false || $value === '') ? $default : (string) $value;
    }
}

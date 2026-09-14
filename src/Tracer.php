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
 *   FILO_EXCLUDE=vendor,storage        comma-separated path substrings to skip
 *   FILO_BREAK_TIMEOUT=120             seconds before a paused breakpoint auto-continues
 *   FILO_PROJECT_ROOT=/app             override project-root discovery (see findProjectRoot)
 *
 * Public API: cycle(). Everything else here is @internal plumbing shared
 * by bootstrap.php, bin/filo and the viewer.
 */
final class Tracer
{
    private static bool $started      = false;
    private static bool $suppressFlush = false;

    /** @internal */
    public static string $cacheDir;
    /** @internal */
    public static string $outputDir;
    /** @internal */
    public static string $breaksDir;
    /** @internal */
    public static string $projectRoot;

    /**
     * @internal
     * @var string[] path substrings that must NOT be instrumented
     */
    public static array $exclude = [];

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
        }
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

        $exclude = array_filter(array_map('trim', explode(',', self::env('FILO_EXCLUDE', 'vendor'))));

        // Never instrument ourselves or our own caches, regardless of config.
        self::$exclude = [
            ...$exclude,
            dirname(__DIR__),   // this package
            self::$cacheDir,
        ];

        if (!is_dir(self::$cacheDir)) {
            @mkdir(self::$cacheDir, 0777, true);
        }
        if (!is_dir(self::$outputDir)) {
            @mkdir(self::$outputDir, 0777, true);
        }

        Collector::begin();
        Debugger::init(
            self::$projectRoot,
            self::$breaksDir,
            (int) self::env('FILO_BREAK_TIMEOUT', '120'),
        );

        // Works for FPM, CLI and the built-in server. Long-running runtimes
        // (Octane, RoadRunner, FrankenPHP worker mode) should instead call
        // Tracer::cycle() at their per-request boundary — see README.
        register_shutdown_function(static function (): void {
            if (!self::$suppressFlush) {
                Collector::flush(self::$outputDir);
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
        $env = self::env('FILO_PROJECT_ROOT', '');
        if ($env !== '') {
            return rtrim($env, '/');
        }

        $installed = dirname(__DIR__, 4);
        if (self::looksLikeProject($installed)) {
            return $installed;
        }

        $script = (string) ($_SERVER['SCRIPT_FILENAME'] ?? '');
        $starts = array_filter([
            $script !== '' ? dirname($script) : '',
            (string) getcwd(),
        ]);

        foreach ($starts as $dir) {
            while ($dir !== '' && $dir !== '.') {
                if (self::looksLikeProject($dir)) {
                    return $dir;
                }
                $parent = dirname($dir);
                if ($parent === $dir) {
                    break;
                }
                $dir = $parent;
            }
        }

        return (string) getcwd();
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

    private static function looksLikeProject(string $dir): bool
    {
        return $dir !== '' && (is_dir($dir . '/.filo') || is_file($dir . '/composer.json'));
    }

    private static function env(string $key, string $default): string
    {
        $value = $_SERVER[$key] ?? getenv($key);

        return ($value === false || $value === null || $value === '') ? $default : (string) $value;
    }
}

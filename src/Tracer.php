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
 */
final class Tracer
{
    private static bool $started = false;

    public static string $cacheDir;
    public static string $outputDir;
    public static string $breaksDir;
    public static string $projectRoot;

    /** @var string[] path substrings that must NOT be instrumented */
    public static array $exclude = [];

    public static function start(): void
    {
        if (self::$started) {
            return;
        }
        self::$started = true;

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
        // Collector::cycle() at their per-request boundary — see README.
        register_shutdown_function(static function (): void {
            Collector::flush(self::$outputDir);
        });

        IncludeStreamWrapper::register();
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

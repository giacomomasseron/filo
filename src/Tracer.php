<?php

declare(strict_types=1);

namespace Filo;

/**
 * Orchestrator. Reads config from the environment (framework-agnostic:
 * no container, no framework config system) and wires everything up.
 *
 * Env vars:
 *   FILO_ENABLED=1                     master switch (checked in bootstrap.php)
 *   FILO_CACHE_DIR=/tmp/filo-cache   instrumented-file cache
 *   FILO_OUTPUT_DIR=/tmp/tracer-out    where JSON traces are written
 *   FILO_EXCLUDE=vendor,storage        comma-separated path substrings to skip
 */
final class Tracer
{
    private static bool $started = false;

    public static string $cacheDir;
    public static string $outputDir;

    /** @var string[] path substrings that must NOT be instrumented */
    public static array $exclude = [];

    public static function start(): void
    {
        if (self::$started) {
            return;
        }
        self::$started = true;

        self::$cacheDir  = self::env('FILO_CACHE_DIR', sys_get_temp_dir() . '/filo-cache');
        self::$outputDir = self::env('FILO_OUTPUT_DIR', sys_get_temp_dir() . '/filo-traces');

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

        // Works for FPM, CLI and the built-in server. Long-running runtimes
        // (Octane, RoadRunner, FrankenPHP worker mode) should instead call
        // Collector::cycle() at their per-request boundary - see README.
        register_shutdown_function(static function (): void {
            Collector::flush(self::$outputDir);
        });

        IncludeStreamWrapper::register();
    }

    private static function env(string $key, string $default): string
    {
        $value = $_SERVER[$key] ?? getenv($key);

        return ($value === false || $value === null || $value === '') ? $default : (string) $value;
    }
}

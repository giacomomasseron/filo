<?php

declare(strict_types=1);

/*
 * Tracer bootstrap.
 *
 * Loaded automatically by Composer ("autoload.files") the moment
 * vendor/autoload.php is included — before any application class
 * is autoloaded, hence before any application file passes through
 * the stream wrapper. (Option B: files loaded *before* the autoloader,
 * e.g. the front controller itself, are not instrumented.)
 */

/*
 * Opt-in only. Two ways to enable, no server restart needed for either:
 *
 *   1. Marker file (recommended, zero-terminal): create an empty
 *      `.filo-on` file in the project root. Delete it to disable.
 *      Checked per request, so toggling is instant.
 *
 *   2. Env var FILO_ENABLED=1 (for CI, docker-compose, CLI one-offs).
 *      Note: a Laravel `.env` entry will NOT work — phpdotenv runs
 *      after this bootstrap. Use the marker file instead.
 */
$tracerEnabled = filter_var($_SERVER['FILO_ENABLED'] ?? getenv('FILO_ENABLED') ?: '0', FILTER_VALIDATE_BOOL);

if (!$tracerEnabled) {
    // Tracer.php is dependency-free; loading it here (before the wrapper
    // exists) is safe and keeps root discovery in ONE place.
    require_once __DIR__ . '/src/Tracer.php';
    $tracerEnabled = is_file(\Filo\Tracer::findProjectRoot() . '/.filo-on');
}

if (!$tracerEnabled) {
    return;
}
unset($tracerEnabled);

// Guard against double bootstrap (e.g. two autoloaders in tests).
if (defined('FILO_BOOTSTRAPPED')) {
    return;
}
define('FILO_BOOTSTRAPPED', true);

/*
 * Load every tracer class eagerly, RIGHT NOW.
 *
 * Critical: once the stream wrapper is registered, any lazily
 * autoloaded class would itself go through the wrapper. If that
 * class is one of ours, we'd recurse (wrapper -> autoload ->
 * wrapper -> ...). Eager-loading makes the tracer self-contained
 * before interception starts.
 */
require_once __DIR__ . '/src/Collector.php';
require_once __DIR__ . '/src/VarExporter.php';
require_once __DIR__ . '/src/Debugger.php';
require_once __DIR__ . '/src/Instrumenter.php';
require_once __DIR__ . '/src/HookVisitor.php';
require_once __DIR__ . '/src/IncludeStreamWrapper.php';
require_once __DIR__ . '/src/Tracer.php';

\Filo\Tracer::start();

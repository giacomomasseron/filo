<?php

declare(strict_types=1);

namespace Filo;

/**
 * <project>/.filo/breakpoints.json, read and written in ONE place so the
 * CLI and the viewer API can never drop entries the other one wrote.
 *
 * Canonical entry: {id, fn, enabled} or {id, file, line, enabled}. Plain
 * strings are accepted on read and from the CLI: "Class::method" (older
 * CLI files) names a function, "path/to/File.php:42" a file and line. A
 * file breakpoint pauses at the entry of the innermost function containing
 * the line — see Debugger.
 *
 * Dependency-free: bin/filo and server/index.php load it without Composer.
 *
 * @internal The FILE format is public (README "Breakpoints"); this class is not.
 *
 * @phpstan-type Entry array{id: string, fn: string, enabled: bool}|array{id: string, file: string, line: int, enabled: bool}
 */
final class Breakpoints
{
    public static function file(string $projectRoot): string
    {
        return $projectRoot . '/.filo/breakpoints.json';
    }

    /** @return list<Entry> */
    public static function read(string $file): array
    {
        // @: Debugger reads this at bootstrap, where a warning would print
        // into the app's output (e.g. the file vanished after is_file()).
        $cfg = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;
        $raw = is_array($cfg) ? ($cfg['breakpoints'] ?? $cfg) : [];

        return array_values(array_filter(array_map(self::normalize(...), (array) $raw)));
    }

    /** @param array<Entry> $list */
    public static function write(string $file, array $list): void
    {
        is_dir(dirname($file)) || @mkdir(dirname($file), 0777, true);
        file_put_contents(
            $file,
            json_encode(['breakpoints' => array_values($list)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        );
    }

    /**
     * A string or an entry object -> the canonical entry, or null. A string
     * like "app/Foo.php:42" is a file breakpoint, any other names a function.
     *
     * @return Entry|null
     */
    public static function normalize(mixed $item): ?array
    {
        if (is_string($item)) {
            $item = ['fn' => $item];
        }
        if (!is_array($item)) {
            return null;
        }
        $fn   = isset($item['fn']) && is_string($item['fn']) ? trim($item['fn']) : '';
        $file = isset($item['file']) && is_string($item['file']) ? trim($item['file']) : '';
        $line = isset($item['line']) && is_numeric($item['line']) ? (int) $item['line'] : 0;
        // A path and line typed where a function goes (the CLI, or a Windows
        // path in the viewer's box). No function name has a / or ends in .php
        // before :<digits>, and closure names start with {.
        if (preg_match('/^([^{].*):(\d+)$/', $fn, $m) === 1
            && (str_contains($m[1], '/') || str_ends_with(strtolower($m[1]), '.php'))) {
            [$fn, $file, $line] = ['', $m[1], (int) $m[2]];
        }
        if ($fn === '' && ($file === '' || $line <= 0)) {
            return null;
        }
        $id      = isset($item['id']) && is_string($item['id']) && $item['id'] !== ''
            ? $item['id']
            : self::idFor($fn !== '' ? $fn : $file . ':' . $line);
        $enabled = !array_key_exists('enabled', $item) || (bool) $item['enabled'];

        return $fn !== ''
            ? ['id' => $id, 'fn' => $fn, 'enabled' => $enabled]
            : ['id' => $id, 'file' => $file, 'line' => $line, 'enabled' => $enabled];
    }

    /**
     * How a breakpoint reads in lists and messages: its function, or file:line.
     *
     * @param Entry $bp
     */
    public static function label(array $bp): string
    {
        return isset($bp['fn']) ? $bp['fn'] : $bp['file'] . ':' . $bp['line'];
    }

    /**
     * Whether two entries are the same breakpoint, ids and `enabled` aside.
     * Files compare resolved, so "app/Foo.php:3" matches its absolute path.
     *
     * @param Entry $a
     * @param Entry $b
     */
    public static function same(array $a, array $b, string $projectRoot): bool
    {
        if (isset($a['fn']) || isset($b['fn'])) {
            return ($a['fn'] ?? null) === ($b['fn'] ?? null);
        }

        return $a['line'] === $b['line']
            && self::fileKey($a['file'], $projectRoot) === self::fileKey($b['file'], $projectRoot);
    }

    /**
     * What a file breakpoint matches instrumented code by: the path resolved
     * against the project root when relative, real when it exists, pathKey()'d.
     */
    public static function fileKey(string $file, string $projectRoot): string
    {
        $path = preg_match('~^([A-Za-z]:)?[/\\\\]~', $file) === 1 ? $file : rtrim($projectRoot, '/\\') . '/' . $file;

        return self::pathKey(realpath($path) ?: $path);
    }

    /** A path spelled the way breakpoints compare paths: forward slashes, lowercase on Windows. */
    public static function pathKey(string $path): string
    {
        $path = strtr($path, '\\', '/');

        return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
    }

    private static function idFor(string $key): string
    {
        return 'bp_' . substr(md5($key), 0, 8);
    }
}

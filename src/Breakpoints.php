<?php

declare(strict_types=1);

namespace Filo;

/**
 * <project>/.filo/breakpoints.json, read and written in ONE place so the
 * CLI and the viewer API can never drop entries the other one wrote.
 *
 * Canonical entry: {id, fn, enabled} or {id, file, line, enabled}. Plain
 * "Class::method" strings (older CLI files) are accepted on read. Only
 * enabled `fn` entries can fire — see Debugger.
 *
 * Dependency-free: bin/filo and server/index.php load it without Composer.
 *
 * @internal The FILE format is public (README "Breakpoints"); this class is not.
 */
final class Breakpoints
{
    public static function file(string $projectRoot): string
    {
        return $projectRoot . '/.filo/breakpoints.json';
    }

    /** @return list<array{id:string, fn?:string, file?:string, line?:int, enabled:bool}> */
    public static function read(string $file): array
    {
        // @: Debugger reads this at bootstrap, where a warning would print
        // into the app's output (e.g. the file vanished after is_file()).
        $cfg = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;
        $raw = is_array($cfg) ? ($cfg['breakpoints'] ?? $cfg) : [];

        return array_values(array_filter(array_map(self::normalize(...), (array) $raw)));
    }

    /** @param array<array{id:string, fn?:string, file?:string, line?:int, enabled:bool}> $list */
    public static function write(string $file, array $list): void
    {
        is_dir(dirname($file)) || @mkdir(dirname($file), 0777, true);
        file_put_contents(
            $file,
            json_encode(['breakpoints' => array_values($list)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        );
    }

    /** A string ("App\\Foo::bar") or an entry object -> the canonical entry, or null. */
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
        if ($fn === '' && ($file === '' || $line <= 0)) {
            return null;
        }
        $out = ['id' => isset($item['id']) && is_string($item['id']) && $item['id'] !== ''
            ? $item['id']
            : 'bp_' . substr(md5($fn !== '' ? $fn : $file . ':' . $line), 0, 8)];
        if ($fn !== '') {
            $out['fn'] = $fn;
        } else {
            $out['file'] = $file;
            $out['line'] = $line;
        }
        $out['enabled'] = !array_key_exists('enabled', $item) || (bool) $item['enabled'];

        return $out;
    }
}

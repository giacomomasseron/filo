<?php

declare(strict_types=1);

namespace Filo;

/**
 * A project's settings: env var > <project>/filo.json > default.
 *
 * filo.json is how people who turn filo on with .filo-on (Herd, Valet: no
 * easy env vars) configure it, and how a team shares one configuration:
 *
 *     {"exclude": ["vendor", "storage"], "keep": 200, "breakTimeout": 120}
 *
 * Read by Tracer at startup and by `filo doctor`, which prints each value's
 * source. Fails open: a broken or mistyped filo.json is ignored value by
 * value, and the problem is reported instead.
 *
 * Dependency-free: bin/filo loads it without Composer.
 *
 * @internal The filo.json format is public (README "Configuration"); this class is not.
 */
final class Settings
{
    public const FILE = 'filo.json';

    /** Setting => the env var that overrides it. */
    private const ENV = [
        'exclude'      => 'FILO_EXCLUDE',
        'keep'         => 'FILO_KEEP',
        'breakTimeout' => 'FILO_BREAK_TIMEOUT',
    ];

    private const DEFAULTS = [
        'exclude'      => ['vendor'],
        'keep'         => 200,
        'breakTimeout' => 120,
    ];

    private const EXPECTED = [
        'exclude'      => 'a list of path substrings',
        'keep'         => 'a whole number >= 0',
        'breakTimeout' => 'a whole number of seconds >= 1',
    ];

    /**
     * @return array{exclude: list<string>, keep: int, breakTimeout: int, sources: array<string, string>, problems: list<string>}
     */
    public static function load(string $projectRoot): array
    {
        $problems = [];
        $file     = self::readFile($projectRoot . '/' . self::FILE, $problems);

        $values  = [];
        $sources = [];
        foreach (self::ENV as $key => $var) {
            $env = $_SERVER[$var] ?? getenv($var);
            if (is_string($env) && $env !== '') {
                $value = self::parse($key, $env, true);
                if ($value !== null) {
                    [$values[$key], $sources[$key]] = [$value, $var];
                    continue;
                }
                $problems[] = sprintf('%s: expected %s, got %s', $var, self::EXPECTED[$key], json_encode($env));
            }
            if (array_key_exists($key, $file)) {
                $value = self::parse($key, $file[$key], false);
                if ($value !== null) {
                    [$values[$key], $sources[$key]] = [$value, self::FILE];
                    continue;
                }
                $problems[] = sprintf('%s: "%s" should be %s, got %s', self::FILE, $key, self::EXPECTED[$key], json_encode($file[$key]));
            }
            [$values[$key], $sources[$key]] = [self::DEFAULTS[$key], 'default'];
        }

        foreach (array_diff(array_keys($file), array_keys(self::ENV)) as $unknown) {
            $problems[] = sprintf('%s: unknown setting "%s"', self::FILE, $unknown);
        }

        /** @var list<string> $exclude */
        $exclude = $values['exclude'];
        /** @var int $keep */
        $keep = $values['keep'];
        /** @var int $breakTimeout */
        $breakTimeout = $values['breakTimeout'];

        return [
            'exclude'      => $exclude,
            'keep'         => $keep,
            'breakTimeout' => $breakTimeout,
            'sources'      => $sources,
            'problems'     => $problems,
        ];
    }

    /**
     * @param list<string> $problems
     * @return array<string, mixed>
     */
    private static function readFile(string $path, array &$problems): array
    {
        if (!is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) @file_get_contents($path), true);
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            $problems[] = self::FILE . ': not a JSON object' . (json_last_error() !== JSON_ERROR_NONE ? ' (' . json_last_error_msg() . ')' : '');

            return [];
        }

        return $decoded;
    }

    /** The setting's value, or null when $raw isn't valid for it. $fromEnv: $raw is an env var string. */
    private static function parse(string $key, mixed $raw, bool $fromEnv): mixed
    {
        if ($key === 'exclude') {
            $list = $fromEnv && is_string($raw) ? explode(',', $raw) : $raw;
            if (!is_array($list) || !array_is_list($list)) {
                return null;
            }
            $out = [];
            foreach ($list as $item) {
                if (!is_string($item)) {
                    return null;
                }
                if (trim($item) !== '') {
                    $out[] = trim($item);
                }
            }

            return $out;
        }

        $min = $key === 'keep' ? 0 : 1;
        if ($fromEnv && is_string($raw) && ctype_digit($raw)) {
            $raw = (int) $raw;
        }

        return is_int($raw) && $raw >= $min ? $raw : null;
    }
}

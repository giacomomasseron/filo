<?php

declare(strict_types=1);

namespace Filo\Tests\Support;

/**
 * Fixtures must live OUTSIDE the package dir (which filo never
 * instruments), so they are written to a temp dir at runtime.
 */
final class TempProject
{
    private static ?string $dir = null;

    public static function dir(): string
    {
        if (self::$dir === null) {
            self::$dir = sys_get_temp_dir() . '/filo-tests-' . getmypid();
            @mkdir(self::$dir, 0777, true);
        }

        return self::$dir;
    }

    /** Writes `$php` (full file content incl. `<?php`) and returns the path. */
    public static function fixture(string $name, string $php): string
    {
        $path = self::dir() . '/' . $name;
        file_put_contents($path, $php);

        return $path;
    }

    public static function cleanup(): void
    {
        if (self::$dir !== null && is_dir(self::$dir)) {
            foreach (glob(self::$dir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir(self::$dir);
        }
    }
}

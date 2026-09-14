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

    /**
     * A fresh, empty project root for a child process (FILO_PROJECT_ROOT).
     * composer.json makes root discovery accept it.
     */
    public static function root(): string
    {
        $root = sys_get_temp_dir() . '/filo-root-' . getmypid() . '-' . bin2hex(random_bytes(3));
        mkdir($root, 0777, true);
        file_put_contents($root . '/composer.json', '{}');

        return $root;
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
        if (self::$dir !== null) {
            self::removeTree(self::$dir);
        }
    }

    /**
     * Removes every temp root this process created (fixtures plus the
     * per-test roots of ArtifactsTest / ThresholdTest / TestArtifactTest /
     * ApiContractTest). Registered as a shutdown function in tests/Pest.php.
     */
    public static function purgeOwn(): void
    {
        self::cleanup();
        $pid = getmypid();
        foreach (['filo-artifacts-' . $pid . '-*', 'filo-threshold-' . $pid . '-*', 'filo-artifact-' . $pid, 'filo-artifact-capped-' . $pid, 'filo-api-' . $pid, 'filo-root-' . $pid . '-*'] as $pattern) {
            foreach (glob(sys_get_temp_dir() . '/' . $pattern) ?: [] as $d) {
                self::removeTree($d);
            }
        }
    }

    /** rm -rf, refusing anything outside this process's filo-* temp dirs. */
    public static function removeTree(string $dir): void
    {
        if (!str_starts_with($dir, sys_get_temp_dir() . '/filo-') || !is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}

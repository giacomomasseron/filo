<?php

declare(strict_types=1);

namespace Filo;

/**
 * Replaces PHP's native "file://" stream wrapper.
 *
 * Every include/require in PHP (and therefore every autoloaded class)
 * goes through this wrapper. When the engine opens a file FOR INCLUSION
 * (STREAM_OPEN_FOR_INCLUDE flag) and the path is eligible, we serve the
 * *instrumented* version of the source from an in-memory stream. The
 * file on disk is never modified. Every other filesystem operation
 * (fopen for read/write, stat, unlink, opendir, ...) is transparently
 * proxied to the real wrapper.
 *
 * Pattern proven by dg/bypass-finals and phpunit's php-code-coverage.
 */
final class IncludeStreamWrapper
{
    private const PROTOCOL = 'file';

    /** Not defined in every SAPI/version; value is stable. */
    private const OPEN_FOR_INCLUDE = 128; // STREAM_OPEN_FOR_INCLUDE

    /** Bump to bust the instrumentation cache when the injector changes. */
    public const VERSION = '1';

    /** @var resource|null underlying handle (real file OR php://memory) */
    private $handle;

    /** @var resource|null directory handle for dir_* ops */
    private $dirHandle;

    /** @var resource|null set by PHP when a context is passed */
    public $context;

    public static function register(): void
    {
        stream_wrapper_unregister(self::PROTOCOL);
        stream_wrapper_register(self::PROTOCOL, self::class);
    }

    public static function unregister(): void
    {
        @stream_wrapper_restore(self::PROTOCOL);
    }

    /**
     * Run $fn with the NATIVE wrapper restored, then re-hook ourselves.
     * Every real filesystem touch inside this class goes through here.
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    private static function native(callable $fn)
    {
        stream_wrapper_restore(self::PROTOCOL);
        try {
            return $fn();
        } finally {
            stream_wrapper_unregister(self::PROTOCOL);
            stream_wrapper_register(self::PROTOCOL, self::class);
        }
    }

    // ---------------------------------------------------------------
    // stream_* : file handles
    // ---------------------------------------------------------------

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $forInclude = (bool) ($options & self::OPEN_FOR_INCLUDE);

        if ($forInclude && self::eligible($path)) {
            $code = self::instrumentedCode($path);

            if ($code !== null) {
                // Serve modified source from memory. We deliberately do NOT
                // set $openedPath to the cache file: __FILE__/__DIR__ inside
                // the included code must keep pointing at the real source.
                $this->handle = fopen('php://memory', 'r+b');
                fwrite($this->handle, $code);
                rewind($this->handle);

                return true;
            }
            // Instrumentation failed (parse error, unreadable) ->
            // fall through and include the original untouched.
        }

        $this->handle = self::native(function () use ($path, $mode, $options, &$openedPath) {
            $usePath = (bool) ($options & STREAM_USE_PATH);
            $report  = (bool) ($options & STREAM_REPORT_ERRORS);

            $h = $this->context !== null
                ? ($report ? fopen($path, $mode, $usePath, $this->context) : @fopen($path, $mode, $usePath, $this->context))
                : ($report ? fopen($path, $mode, $usePath) : @fopen($path, $mode, $usePath));

            if ($h !== false && $usePath) {
                $meta = stream_get_meta_data($h);
                $openedPath = $meta['uri'] ?? $path;
            }

            return $h;
        });

        return is_resource($this->handle);
    }

    public function stream_read(int $count): string|false
    {
        return fread($this->handle, $count);
    }

    public function stream_write(string $data): int|false
    {
        return fwrite($this->handle, $data);
    }

    public function stream_eof(): bool
    {
        return feof($this->handle);
    }

    public function stream_close(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
        $this->handle = null;
    }

    public function stream_stat(): array|false
    {
        return fstat($this->handle);
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        return fseek($this->handle, $offset, $whence) === 0;
    }

    public function stream_tell(): int
    {
        return ftell($this->handle);
    }

    public function stream_flush(): bool
    {
        return fflush($this->handle);
    }

    public function stream_truncate(int $newSize): bool
    {
        return ftruncate($this->handle, $newSize);
    }

    public function stream_lock(int $operation): bool
    {
        // LOCK_* with 0 means "release info request" from some callers.
        return $operation === 0 ? false : flock($this->handle, $operation);
    }

    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
    {
        return match ($option) {
            STREAM_OPTION_BLOCKING     => stream_set_blocking($this->handle, (bool) $arg1),
            STREAM_OPTION_READ_TIMEOUT => stream_set_timeout($this->handle, $arg1, $arg2 ?? 0),
            STREAM_OPTION_WRITE_BUFFER => stream_set_write_buffer($this->handle, $arg2 ?? 0) === 0,
            default => false,
        };
    }

    public function stream_metadata(string $path, int $option, mixed $value): bool
    {
        return self::native(static fn (): bool => match ($option) {
            STREAM_META_TOUCH       => touch($path, ...array_filter((array) $value, fn ($v) => $v !== null)),
            STREAM_META_OWNER       => chown($path, $value),
            STREAM_META_OWNER_NAME  => chown($path, $value),
            STREAM_META_GROUP       => chgrp($path, $value),
            STREAM_META_GROUP_NAME  => chgrp($path, $value),
            STREAM_META_ACCESS      => chmod($path, $value),
            default => false,
        });
    }

    public function stream_cast(int $castAs)
    {
        return $this->handle ?: false;
    }

    // ---------------------------------------------------------------
    // url_stat + filesystem ops
    // ---------------------------------------------------------------

    public function url_stat(string $path, int $flags): array|false
    {
        return self::native(static function () use ($path, $flags) {
            // QUIET: file_exists() etc. must not raise warnings.
            if ($flags & STREAM_URL_STAT_QUIET) {
                return ($flags & STREAM_URL_STAT_LINK) ? @lstat($path) : @stat($path);
            }

            return ($flags & STREAM_URL_STAT_LINK) ? lstat($path) : stat($path);
        });
    }

    public function unlink(string $path): bool
    {
        return self::native(static fn (): bool => unlink($path));
    }

    public function rename(string $from, string $to): bool
    {
        return self::native(static fn (): bool => rename($from, $to));
    }

    public function mkdir(string $path, int $mode, int $options): bool
    {
        $recursive = (bool) ($options & STREAM_MKDIR_RECURSIVE);

        return self::native(static fn (): bool => mkdir($path, $mode, $recursive));
    }

    public function rmdir(string $path, int $options): bool
    {
        return self::native(static fn (): bool => rmdir($path));
    }

    // ---------------------------------------------------------------
    // dir_* : directory handles
    // ---------------------------------------------------------------

    public function dir_opendir(string $path, int $options): bool
    {
        $this->dirHandle = self::native(static fn () => @opendir($path));

        return is_resource($this->dirHandle);
    }

    public function dir_readdir(): string|false
    {
        return readdir($this->dirHandle);
    }

    public function dir_rewinddir(): bool
    {
        rewinddir($this->dirHandle);

        return true;
    }

    public function dir_closedir(): bool
    {
        closedir($this->dirHandle);
        $this->dirHandle = null;

        return true;
    }

    // ---------------------------------------------------------------
    // Instrumentation plumbing
    // ---------------------------------------------------------------

    private static function eligible(string $path): bool
    {
        if (!str_ends_with($path, '.php')) {
            return false;
        }

        foreach (Tracer::$exclude as $needle) {
            if ($needle !== '' && str_contains($path, $needle)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Returns instrumented source for $path, using the on-disk cache
     * (keyed on realpath + mtime + injector version). Null on failure,
     * in which case the caller serves the original file.
     */
    private static function instrumentedCode(string $path): ?string
    {
        return self::native(static function () use ($path): ?string {
            $real = realpath($path);
            if ($real === false || !is_readable($real)) {
                return null;
            }

            $mtime = @filemtime($real);
            $key   = md5(self::VERSION . '|' . Instrumenter::VERSION . '|' . $real . '|' . $mtime);
            $cache = Tracer::$cacheDir . '/' . $key . '.php';

            if (is_file($cache)) {
                $code = @file_get_contents($cache);

                return $code === false ? null : $code;
            }

            $source = @file_get_contents($real);
            if ($source === false) {
                return null;
            }

            $code = Instrumenter::instrument($source);
            if ($code === null) {
                return null; // parse failure -> leave file untouched
            }

            // Atomic-ish write so parallel FPM workers never read half a file.
            $tmp = $cache . '.' . getmypid() . '.tmp';
            if (@file_put_contents($tmp, $code) !== false) {
                @rename($tmp, $cache);
            }

            return $code;
        });
    }
}

<?php

declare(strict_types=1);

test('proc_open with a file descriptor works under the wrapper', function (): void {
    if (!defined('FILO_BOOTSTRAPPED')) {
        $this->markTestSkipped('needs FILO_ENABLED=1');
    }
    $tmp = sys_get_temp_dir() . '/filo-proc-' . bin2hex(random_bytes(4)) . '.txt';

    $proc = proc_open(
        [PHP_BINARY, '-r', 'echo "hi";'],
        [1 => ['file', $tmp, 'w']],
        $pipes,
    );

    expect($proc)->toBeResource();
    proc_close($proc);

    try {
        expect(file_get_contents($tmp))->toBe('hi');
    } finally {
        @unlink($tmp);
    }
});

test('a non-seekable file descriptor raises no warning under the wrapper', function (): void {
    if (!defined('FILO_BOOTSTRAPPED')) {
        $this->markTestSkipped('needs FILO_ENABLED=1');
    }
    $seen = [];
    set_error_handler(static function (int $no, string $msg) use (&$seen): bool { $seen[] = $msg; return true; });
    try {
        // The null device cannot seek; PHP seeks the stream while casting it.
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $proc = proc_open([PHP_BINARY, '-r', 'echo "hi";'], [1 => ['file', $null, 'w']], $pipes);
        is_resource($proc) && proc_close($proc);
    } finally {
        restore_error_handler();
    }

    expect($seen)->toBe([]);
});

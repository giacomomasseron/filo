<?php

declare(strict_types=1);

test('file_put_contents with LOCK_EX writes through the wrapper without warnings', function (): void {
    if (!defined('FILO_BOOTSTRAPPED')) {
        $this->markTestSkipped('needs FILO_ENABLED=1');
    }
    $path = sys_get_temp_dir() . '/filo-lock-' . bin2hex(random_bytes(4)) . '.txt';
    $seen = [];
    set_error_handler(static function (int $no, string $msg) use (&$seen): bool { $seen[] = $msg; return true; });
    try {
        $written = file_put_contents($path, 'x', LOCK_EX);
        $content = file_get_contents($path);
    } finally {
        restore_error_handler();
        @unlink($path);
    }

    expect($written)->toBe(1)
        ->and($content)->toBe('x')
        ->and($seen)->toBe([]);
});

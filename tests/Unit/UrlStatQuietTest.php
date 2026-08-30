<?php

declare(strict_types=1);

test('is_dir/file_exists on a missing path through the wrapper raise no warning', function (): void {
    if (!defined('FILO_BOOTSTRAPPED')) {
        $this->markTestSkipped('needs FILO_ENABLED=1');
    }
    $missing = sys_get_temp_dir() . '/filo-missing-' . bin2hex(random_bytes(4));
    $seen = [];
    set_error_handler(static function (int $no, string $msg) use (&$seen): bool { $seen[] = $msg; return true; });
    try {
        $a = is_dir($missing);
        $b = file_exists($missing . '/x');
        $c = is_link($missing);
    } finally {
        restore_error_handler();
    }
    expect($a)->toBeFalse()->and($b)->toBeFalse()->and($c)->toBeFalse()->and($seen)->toBe([]);
});

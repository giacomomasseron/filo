<?php

declare(strict_types=1);

/*
 * Breakpoint demo — needs two terminals.
 *
 * Terminal 1 (project root):
 *     vendor/bin/filo break "demo_checkout"
 *     FILO_ENABLED=1 php -d opcache.enable_cli=0 examples/break-demo.php
 *     -> prints "...frozen", then waits
 *
 * Terminal 2:
 *     vendor/bin/filo pending
 *     vendor/bin/filo show <id>        # you should see $order and __this-free locals
 *     vendor/bin/filo continue --all
 *
 * Terminal 1 resumes and prints the total. The trace JSON's timings
 * for demo_checkout should NOT include the time you spent inspecting.
 */

require __DIR__ . '/../vendor/autoload.php';

if (!defined('FILO_BOOTSTRAPPED')) {
    fwrite(STDERR, "Filo not enabled — run with FILO_ENABLED=1 (or touch .filo-on)\n");
    exit(1);
}

// Fixture goes to temp dir: the package's own directory is never instrumented.
$fixture = sys_get_temp_dir() . '/filo-break-demo-' . getmypid() . '.php';
file_put_contents($fixture, <<<'PHP'
<?php
function demo_checkout(array $order): float {
    $total = 0.0;
    foreach ($order['items'] as $item) {
        $total += $item['price'] * $item['qty'];
    }
    return $total;
}
PHP);

require $fixture;

echo "calling demo_checkout() — if a breakpoint is set, this request is now frozen…\n";

$total = demo_checkout([
    'id'    => 'ord_123',
    'items' => [
        ['sku' => 'ESP-01', 'price' => 549.00, 'qty' => 1],
        ['sku' => 'CUP-11', 'price' => 12.50,  'qty' => 4],
    ],
]);

@unlink($fixture);

printf("resumed. total: %.2f\n", $total);
echo "now check the newest trace in the output dir: demo_checkout's duration\n";
echo "should be microseconds, not the seconds you spent inspecting.\n";

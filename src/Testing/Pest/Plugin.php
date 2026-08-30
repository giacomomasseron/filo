<?php

declare(strict_types=1);

namespace Filo\Testing\Pest;

use Pest\Contracts\Plugins\Bootable;

/**
 * Registered via composer.json → extra.pest.plugins. Loads the custom
 * expectations once per process. The per-test artifact extension is a
 * PHPUnit extension registered in phpunit.xml (Pest honours it) — see
 * Filo\Testing\PHPUnit\TraceExtension.
 */
final class Plugin implements Bootable
{
    public function boot(): void
    {
        require_once __DIR__ . '/Expectations.php';
    }
}

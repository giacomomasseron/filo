<?php

declare(strict_types=1);

namespace Filo\Testing;

use Attribute;

/**
 * Mark a test method (or a whole test class) so a trace file is written
 * for it even when it passes: <project>/.filo/traces/tests/<Class>__<method>.json
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final class Traced
{
}

<?php

declare(strict_types=1);

namespace Filo\Testing;

/** Framework-neutral assertion failure; adapters convert it to their own failure type. */
final class ExpectationFailed extends \RuntimeException
{
}

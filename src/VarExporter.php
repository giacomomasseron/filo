<?php

declare(strict_types=1);

namespace Filo;

use Closure;
use UnitEnum;

/**
 * Converts arbitrary PHP values into a JSON-safe structure for
 * breakpoint snapshots. Depth-limited and size-limited on purpose:
 * a breakpoint in a controller can be holding an Eloquent model
 * graph — we want a useful glance, not a 40MB dump.
 */
final class VarExporter
{
    private const MAX_DEPTH        = 3;
    private const MAX_ARRAY_ITEMS  = 25;
    private const MAX_STRING_BYTES = 2048;

    /** @param array<string, mixed> $vars */
    public static function snapshot(array $vars): array
    {
        // The injected hook's own local ($__trc, see HookVisitor) is in
        // scope when get_defined_vars() runs — never show it to users.
        unset($vars['__trc']);

        $out = [];
        foreach ($vars as $name => $value) {
            $out[$name] = self::export($value, self::MAX_DEPTH);
        }

        return $out;
    }

    private static function export(mixed $value, int $depth): mixed
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value)) {
            if (strlen($value) <= self::MAX_STRING_BYTES) {
                return $value;
            }

            return substr($value, 0, self::MAX_STRING_BYTES)
                . sprintf('… [truncated, %d bytes total]', strlen($value));
        }

        if (is_resource($value)) {
            return sprintf('resource(%s)', get_resource_type($value));
        }

        if (is_array($value)) {
            if ($depth <= 0) {
                return sprintf('array(%d) …', count($value));
            }

            $out = [];
            $i   = 0;
            foreach ($value as $k => $v) {
                if (++$i > self::MAX_ARRAY_ITEMS) {
                    $out['…'] = sprintf('+%d more items', count($value) - self::MAX_ARRAY_ITEMS);
                    break;
                }
                $out[$k] = self::export($v, $depth - 1);
            }

            return $out;
        }

        if ($value instanceof Closure) {
            return 'Closure';
        }

        if ($value instanceof UnitEnum) {
            return $value::class . '::' . $value->name;
        }

        if (is_object($value)) {
            if ($depth <= 0) {
                return ['__class' => $value::class];
            }

            $props = [];
            $i     = 0;
            // (array) cast exposes private/protected props with \0-mangled
            // keys — strip the mangling so the UI shows plain names.
            foreach ((array) $value as $k => $v) {
                if (++$i > self::MAX_ARRAY_ITEMS) {
                    $props['…'] = 'more properties';
                    break;
                }
                $pos = strrpos((string) $k, "\0");
                $key = $pos === false ? (string) $k : substr((string) $k, $pos + 1);
                $props[$key] = self::export($v, $depth - 1);
            }

            return ['__class' => $value::class, 'props' => $props];
        }

        return '(unexportable)';
    }
}

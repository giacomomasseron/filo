<?php

declare(strict_types=1);

namespace Filo;

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use Throwable;

/**
 * Source-to-source transform: inject Collector::enter()/leave() hooks
 * into every function, method and closure body.
 *
 * Returns null on any failure — the wrapper then serves the ORIGINAL
 * file, so a parse error in exotic code can never take the app down.
 */
final class Instrumenter
{
    /** Part of the cache key: bump when the transform changes. */
    public const VERSION = '4';

    public static function instrument(string $source): ?string
    {
        try {
            $parser = (new ParserFactory())->createForHostVersion();

            $ast = $parser->parse($source);
            if ($ast === null) {
                return null;
            }

            $traverser = new NodeTraverser();
            $traverser->addVisitor(new HookVisitor());
            $ast = $traverser->traverse($ast);

            return (new Standard())->prettyPrintFile($ast);

            /*
             * Known trade-off of the standard pretty printer: line numbers
             * in the instrumented file drift from the original, so uncaught
             * exception traces may show shifted lines. v2 options:
             *   a) php-parser's format-preserving printer (keeps original
             *      formatting/lines for untouched nodes), or
             *   b) token-level injection instead of full re-print.
             * Our own enter() hooks record the ORIGINAL start line via the
             * node's getStartLine(), so trace data is always correct.
             */
        } catch (Throwable) {
            return null;
        }
    }
}

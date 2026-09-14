<?php

declare(strict_types=1);

namespace Filo;

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use Throwable;

/**
 * Source-to-source transform: inject Collector::enter()/leave() hooks
 * into every function, method and closure body — by inserting text into
 * the original source (see HookVisitor), never by re-printing it, so every
 * line keeps its number.
 *
 * Returns null on any failure — the wrapper then serves the ORIGINAL
 * file, so a parse error in exotic code can never take the app down.
 */
final class Instrumenter
{
    /** Part of the cache key: bump when the transform changes. */
    public const VERSION = '5';

    public static function instrument(string $source): ?string
    {
        try {
            $parser = (new ParserFactory())->createForHostVersion();

            $ast = $parser->parse($source);
            if ($ast === null) {
                return null;
            }

            $hooks     = new HookVisitor($parser->getTokens());
            $traverser = new NodeTraverser();
            $traverser->addVisitor($hooks);
            $traverser->traverse($ast);

            $code = self::splice($source, $hooks->insertions());

            // Fail open: a spliced file that doesn't parse (it always
            // should) throws here, and the original is served instead.
            $parser->parse($code);

            return $code;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * One pass over the source, inserting each text at its offset in the
     * ORIGINAL string.
     *
     * @param list<array{int, string}> $insertions [offset, text]
     */
    private static function splice(string $source, array $insertions): string
    {
        usort($insertions, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $out = '';
        $at  = 0;
        foreach ($insertions as [$offset, $text]) {
            $out .= substr($source, $at, $offset - $at) . $text;
            $at   = $offset;
        }

        return $out . substr($source, $at);
    }
}

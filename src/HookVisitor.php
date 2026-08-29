<?php

declare(strict_types=1);

namespace Filo;

use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\MagicConst\File;
use PhpParser\Node\Scalar\MagicConst\Method;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Finally_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\TryCatch;
use PhpParser\NodeVisitorAbstract;

/**
 * Transforms
 *
 *      function foo($x) { <body> }
 *
 * into
 *
 *      function foo($x) {
 *          $__trc = \Filo\Collector::enter(__METHOD__, __FILE__, 42);
 *          try { <body> }
 *          finally { \Filo\Collector::leave($__trc); }
 *      }
 *
 * Design notes:
 *  - __METHOD__ resolves at compile time to "Class::method" inside
 *    methods, the plain function name inside functions, and "{closure}"
 *    inside closures - so we never need to reconstruct names ourselves.
 *  - The line number is baked in as a literal from the ORIGINAL node
 *    (getStartLine()), so traces stay correct even though the pretty
 *    printer shifts lines.
 *  - try/finally guarantees leave() on return, exception, and even
 *    generator destruction - so timings can't leak open frames.
 *  - Arrow functions (fn() => ...) are skipped in v1: they have no
 *    statement body to wrap. They appear as self-time of their caller,
 *    same as native functions.
 *  - Abstract/interface methods have $stmts === null -> skipped.
 *  - Generators: enter() fires when the body first executes (first
 *    iteration), not at call time; leave() fires when the generator
 *    completes or is destroyed. The duration is "generator lifetime",
 *    which is the honest number anyway.
 */
final class HookVisitor extends NodeVisitorAbstract
{
    private const COLLECTOR = 'Filo\\Collector';
    private const VAR       = '__trc';

    public function leaveNode(Node $node): ?Node
    {
        if (!$node instanceof Function_ && !$node instanceof ClassMethod && !$node instanceof Closure) {
            return null;
        }

        if ($node->stmts === null || $node->stmts === []) {
            return null; // abstract, interface, or empty body - nothing to time
        }

        $enter = new Expression(
            new Assign(
                new Variable(self::VAR),
                new StaticCall(
                    new FullyQualified(self::COLLECTOR),
                    'enter',
                    [
                        new Node\Arg(new Method()),                    // __METHOD__
                        new Node\Arg(new File()),                      // __FILE__
                        new Node\Arg(new Int_($node->getStartLine())), // original line, literal
                    ],
                ),
            ),
        );

        $leave = new Expression(
            new StaticCall(
                new FullyQualified(self::COLLECTOR),
                'leave',
                [new Node\Arg(new Variable(self::VAR))],
            ),
        );

        $node->stmts = [
            $enter,
            new TryCatch($node->stmts, [], new Finally_([$leave])),
        ];

        return $node;
    }
}

<?php

declare(strict_types=1);

namespace Filo;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\BinaryOp\BooleanAnd;
use PhpParser\Node\Expr\BinaryOp\Plus;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\MagicConst\File;
use PhpParser\Node\Scalar\MagicConst\Method;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Finally_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\If_;
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
 *          try {
 *              if (\Filo\Debugger::$armed && \Filo\Debugger::hit(__METHOD__)) {
 *                  \Filo\Debugger::pause(__METHOD__, get_defined_vars(), __FILE__, 42);
 *              }
 *              <body>
 *          } finally { \Filo\Collector::leave($__trc); }
 *      }
 *
 * Design notes:
 *  - __METHOD__ resolves at compile time to "Class::method" inside
 *    methods, the plain function name inside functions, and "{closure}"
 *    inside closures — so we never need to reconstruct names ourselves.
 *  - The line number is baked in as a literal from the ORIGINAL node
 *    (getStartLine()), so traces stay correct even though the pretty
 *    printer shifts lines.
 *  - Breakpoints are ENTRY breakpoints: get_defined_vars() at the top
 *    of the body captures the arguments. For non-static methods we also
 *    pass ['__this' => $this]; static context and closures skip it.
 *  - The disarmed cost is one static property read (Debugger::$armed);
 *    hit() is only called when breakpoints exist, and get_defined_vars()
 *    (which copies) only runs inside the taken branch.
 *  - try/finally guarantees leave() on return, exception, and even
 *    generator destruction — so timings can't leak open frames. The
 *    breakpoint check sits INSIDE the try so a throwing pause() (e.g.
 *    random_bytes() without an entropy source) can't leak a frame either.
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
    private const DEBUGGER  = 'Filo\\Debugger';
    private const VAR       = '__trc';

    public function leaveNode(Node $node): ?Node
    {
        if (!$node instanceof Function_ && !$node instanceof ClassMethod && !$node instanceof Closure) {
            return null;
        }

        if ($node->stmts === null || $node->stmts === []) {
            return null; // abstract, interface, or empty body — nothing to time
        }

        $line = new Int_($node->getStartLine());

        $enter = new Expression(
            new Assign(
                new Variable(self::VAR),
                new StaticCall(
                    new FullyQualified(self::COLLECTOR),
                    'enter',
                    [new Arg(new Method()), new Arg(new File()), new Arg($line)],
                ),
            ),
        );

        // get_defined_vars() [+ ['__this' => $this] for non-static methods]
        $varsExpr = new FuncCall(new Name('get_defined_vars'));
        if ($node instanceof ClassMethod && !$node->isStatic()) {
            $varsExpr = new Plus(
                $varsExpr,
                new Array_([new ArrayItem(new Variable('this'), new String_('__this'))]),
            );
        }

        $breakCheck = new If_(
            new BooleanAnd(
                new StaticPropertyFetch(new FullyQualified(self::DEBUGGER), 'armed'),
                new StaticCall(new FullyQualified(self::DEBUGGER), 'hit', [new Arg(new Method())]),
            ),
            ['stmts' => [
                new Expression(
                    new StaticCall(
                        new FullyQualified(self::DEBUGGER),
                        'pause',
                        [
                            new Arg(new Method()),
                            new Arg($varsExpr),
                            new Arg(new File()),
                            new Arg($line),
                        ],
                    ),
                ),
            ]],
        );

        $leave = new Expression(
            new StaticCall(
                new FullyQualified(self::COLLECTOR),
                'leave',
                [new Arg(new Variable(self::VAR))],
            ),
        );

        $node->stmts = [
            $enter,
            new TryCatch([$breakCheck, ...$node->stmts], [], new Finally_([$leave])),
        ];

        return $node;
    }
}

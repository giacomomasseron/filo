<?php

declare(strict_types=1);

namespace Filo;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Token;
use RuntimeException;

/**
 * Plans the hooks for every function, method and closure body as two text
 * insertions into the ORIGINAL source (Instrumenter applies them):
 *
 *      function foo($x) {⟨prologue⟩ <body, untouched> ⟨epilogue⟩}
 *
 *   prologue, right after the body's `{`:
 *      $__trc = \Filo\Collector::enter(__METHOD__, __FILE__, 42); try {
 *      if (\Filo\Debugger::$armed && \Filo\Debugger::hit(__METHOD__, '/app/src/Foo.php', 42, 60, [[45, 49]])) {
 *      \Filo\Debugger::pause(__METHOD__, get_defined_vars(), __FILE__, 42); }
 *   epilogue, right before the closing `}`:
 *      } finally { \Filo\Collector::leave($__trc); }
 *
 * Neither contains a newline, so every line keeps its number: exception
 * lines, __LINE__ and stack traces stay those of the source. Only columns
 * on the two brace lines shift.
 *
 * Design notes:
 *  - Functions and methods are named by __METHOD__, which resolves at
 *    compile time to "Class::method" / "func" (a trait method keeps the
 *    trait's name, as in PHP).
 *  - Closures get a name baked in as a literal, the PHP 8.4 way:
 *    {closure:<enclosing function, method or closure>:<line>}, e.g.
 *    {closure:App\Repo::find():12}, or {closure:<real path>:<line>} at the
 *    top level. Identical on every PHP version (before 8.4 PHP only says
 *    "{closure}"), so traces, toCall() patterns and breakpoints agree.
 *    Inside an anonymous class the scope reads "class@anonymous::m()"
 *    (PHP 8.4 embeds a path and a compile counter there instead).
 *  - The line passed to enter()/pause() is the function's start line.
 *  - hit() also gets the file (Breakpoints::pathKey() of its real path),
 *    the function's first and last line, and the line ranges of the hooked
 *    functions nested in it, so a file:line breakpoint fires at the entry
 *    of the innermost hooked function containing the line. The nested
 *    ranges are known once the body has been traversed: the insertions
 *    are made in leaveNode().
 *  - Breakpoints are ENTRY breakpoints: get_defined_vars() at the top
 *    of the body captures the arguments. For non-static methods we also
 *    pass ['__this' => $this]; static context and closures skip it.
 *    Parameters marked #[\SensitiveParameter] are named in a 5th pause()
 *    argument so their values never reach a snapshot.
 *  - The disarmed cost is one static property read (Debugger::$armed);
 *    hit() is only called when breakpoints exist, and get_defined_vars()
 *    (which copies) only runs inside the taken branch.
 *  - try/finally guarantees leave() on return, exception, and even
 *    generator destruction — so timings can't leak open frames. The
 *    breakpoint check sits INSIDE the try so a throwing pause() (e.g.
 *    random_bytes() without an entropy source) can't leak a frame either.
 *  - Arrow functions (fn() => ...) are not hooked: they have no statement
 *    body to wrap, and show up as self-time of their caller, same as
 *    native functions. They still name the closures declared inside them.
 *  - Abstract/interface methods and empty bodies are skipped.
 *  - Generators: enter() fires when the body first executes (first
 *    iteration), not at call time; leave() fires when the generator
 *    completes or is destroyed. The duration is "generator lifetime",
 *    which is the honest number anyway.
 *
 * @internal Not part of the public API (README "Public API").
 */
final class HookVisitor extends NodeVisitorAbstract
{
    /** @var list<array{int, string}> [byte offset in the source, text to insert there] */
    private array $insertions = [];

    private string $namespace = '';

    /** @var list<string> enclosing class-like names, innermost last */
    private array $classes = [];

    /** @var list<string> what a closure declared here is named after, innermost last */
    private array $scopes = [];

    /**
     * Function-likes being traversed, innermost last: the parts of the hook
     * for a body that gets one, null for one that doesn't.
     *
     * @var array<int, array{open: int, close: int, fn: string, line: int, end: int, args: string, nested: list<array{int, int}>}|null>
     */
    private array $frames = [];

    /** The file as hit() compares it, as a PHP literal. */
    private readonly string $fileKey;

    /**
     * @param array<int, Token> $tokens the parser's tokens for the same source
     * @param string            $file   real path of the source: names top-level closures, locates line breakpoints
     */
    public function __construct(
        private readonly array $tokens,
        private readonly string $file = '',
    ) {
        $this->fileKey = var_export($file === '' ? '' : Breakpoints::pathKey($file), true);
    }

    /** @return list<array{int, string}> */
    public function insertions(): array
    {
        return $this->insertions;
    }

    public function enterNode(Node $node): ?Node
    {
        if ($node instanceof Namespace_) {
            $this->namespace = $node->name === null ? '' : $node->name->toString() . '\\';

            return null;
        }

        if ($node instanceof ClassLike) {
            $this->classes[] = $node->name === null ? 'class@anonymous' : $this->namespace . $node->name->toString();

            return null;
        }

        if ($node instanceof ArrowFunction) {
            $this->scopes[] = $this->closureName($node) ?? '{closure}';

            return null;
        }

        if (!$node instanceof Function_ && !$node instanceof ClassMethod && !$node instanceof Closure) {
            return null;
        }

        $closureName    = $node instanceof Closure ? $this->closureName($node) : null;
        $this->scopes[] = match (true) {
            $node instanceof Closure     => $closureName ?? '{closure}',
            $node instanceof ClassMethod => ($this->classes[array_key_last($this->classes)] ?? '') . '::' . $node->name->toString() . '()',
            default                      => $this->namespace . $node->name->toString() . '()',
        };

        if ($node->stmts === null || $node->stmts === []) {
            $this->frames[] = null;

            return null; // abstract, interface, or empty body — nothing to time
        }

        [$open, $close] = $this->bodyBraces($node);

        $fn   = $closureName === null ? '__METHOD__' : var_export($closureName, true);
        $line = $node->getStartLine();
        $args = 'get_defined_vars()';
        if ($node instanceof ClassMethod && !$node->isStatic()) {
            $args .= " + ['__this' => \$this]";
        }
        $args .= ', __FILE__, ' . $line;
        $sensitive = self::sensitiveParams($node);
        if ($sensitive !== []) {
            $args .= ', [' . implode(', ', array_map(
                static fn (string $name): string => var_export($name, true),
                $sensitive,
            )) . ']';
        }

        $this->frames[] = [
            'open' => $open, 'close' => $close, 'fn' => $fn, 'line' => $line,
            'end'  => $node->getEndLine(), 'args' => $args, 'nested' => [],
        ];

        return null;
    }

    public function leaveNode(Node $node): ?Node
    {
        if ($node instanceof Namespace_) {
            $this->namespace = '';
        } elseif ($node instanceof ClassLike) {
            array_pop($this->classes);
        } elseif ($node instanceof ArrowFunction) {
            array_pop($this->scopes);
        } elseif ($node instanceof Function_ || $node instanceof ClassMethod || $node instanceof Closure) {
            array_pop($this->scopes);
            $frame = array_pop($this->frames);
            if ($frame !== null) {
                $this->hook($frame);
                // Its lines belong to it, not to the hooked function around it.
                for ($i = count($this->frames) - 1; $i >= 0; $i--) {
                    if ($this->frames[$i] !== null) {
                        $this->frames[$i]['nested'][] = [$frame['line'], $frame['end']];
                        break;
                    }
                }
            }
        }

        return null;
    }

    /** @param array{open: int, close: int, fn: string, line: int, end: int, args: string, nested: list<array{int, int}>} $frame */
    private function hook(array $frame): void
    {
        $fn    = $frame['fn'];
        $where = "{$this->fileKey}, {$frame['line']}, {$frame['end']}";
        if ($frame['nested'] !== []) {
            $where .= ', [' . implode(', ', array_map(
                static fn (array $range): string => "[{$range[0]}, {$range[1]}]",
                $frame['nested'],
            )) . ']';
        }

        $this->insertions[] = [$frame['open'] + 1, " \$__trc = \\Filo\\Collector::enter({$fn}, __FILE__, {$frame['line']}); try { "
            . "if (\\Filo\\Debugger::\$armed && \\Filo\\Debugger::hit({$fn}, {$where})) { "
            . "\\Filo\\Debugger::pause({$fn}, {$frame['args']}); } "];
        $this->insertions[] = [$frame['close'], ' } finally { \Filo\Collector::leave($__trc); } '];
    }

    /**
     * {closure:<scope>:<line>}, where scope is the enclosing function-like
     * or, at the top level, the file. Null when the file is unknown.
     */
    private function closureName(Closure|ArrowFunction $node): ?string
    {
        $scope = $this->scopes === [] ? $this->file : $this->scopes[array_key_last($this->scopes)];

        return $scope === '' ? null : '{closure:' . $scope . ':' . $node->getStartLine() . '}';
    }

    /**
     * Byte offsets of the body's `{` and `}`. The node ends with its `}`;
     * the matching `{` is found by counting braces backwards over the
     * tokens, so closures in default values, "{$x}" in strings and nested
     * bodies can't be mistaken for it. Throwing makes Instrumenter serve
     * the original file.
     *
     * @return array{int, int}
     */
    private function bodyBraces(Function_|ClassMethod|Closure $node): array
    {
        $end = $node->getEndTokenPos();
        if (($this->tokens[$end] ?? null)?->text !== '}') {
            throw new RuntimeException('function body does not end with }');
        }

        $depth = 0;
        for ($i = $end; $i >= 0; $i--) {
            $text = $this->tokens[$i]->text;
            if ($text === '}') {
                $depth++;
            } elseif (($text === '{' || $text === '${') && --$depth === 0) {
                return [$this->tokens[$i]->pos, $this->tokens[$end]->pos];
            }
        }

        throw new RuntimeException('unbalanced braces');
    }

    /**
     * Names of the parameters marked #[\SensitiveParameter]. Matched on the
     * last name segment, so imported and unqualified spellings count too.
     *
     * @return list<string>
     */
    private static function sensitiveParams(Function_|ClassMethod|Closure $node): array
    {
        $names = [];
        foreach ($node->params as $param) {
            foreach ($param->attrGroups as $group) {
                foreach ($group->attrs as $attr) {
                    if (strcasecmp($attr->name->getLast(), 'SensitiveParameter') === 0
                        && $param->var instanceof Variable
                        && is_string($param->var->name)) {
                        $names[] = $param->var->name;
                    }
                }
            }
        }

        return $names;
    }
}

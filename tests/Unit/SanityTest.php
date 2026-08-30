<?php

declare(strict_types=1);

test('php-parser and the Filo namespace are loadable', function (): void {
    expect(class_exists(\PhpParser\Parser::class) || interface_exists(\PhpParser\Parser::class))->toBeTrue()
        ->and(class_exists(\Filo\Collector::class))->toBeTrue();
});

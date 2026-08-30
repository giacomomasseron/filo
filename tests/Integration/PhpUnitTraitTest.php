<?php

declare(strict_types=1);

namespace Filo\Tests\Integration;

use Filo\Testing\FiloAssertions;
use Filo\Testing\Recorder;
use Filo\Tests\Support\TempProject;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

final class PhpUnitTraitTest extends TestCase
{
    use FiloAssertions;

    protected function setUp(): void
    {
        if (!Recorder::enabled()) {
            self::markTestSkipped('needs FILO_ENABLED=1');
        }
        if (!\function_exists('filo_trait_repo_find')) {
            require TempProject::fixture('trait_fixture.php', <<<'PHP'
<?php
function filo_trait_repo_find(int $id): int { return $id; }
function filo_trait_list(int $n): array {
    $out = [];
    for ($i = 0; $i < $n; $i++) { $out[] = filo_trait_repo_find($i); }
    return $out;
}
PHP);
        }
    }

    public function testCaptureAndCallCount(): void
    {
        $trace = $this->capture(fn (): array => filo_trait_list(3));

        self::assertSame([0, 1, 2], $trace->result());
        $this->assertTraceCallCount($trace, 'filo_trait_repo_find', atMost: 3);
        $this->assertTraceRunsUnder($trace, 500);
    }

    public function testAssertRunsUnderReturnsTheTrace(): void
    {
        $trace = $this->assertRunsUnder(500, fn (): array => filo_trait_list(1));
        self::assertSame(1, $trace->calls('filo_trait_list'));
    }

    public function testFailuresAreAssertionFailures(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('filo_trait_repo_find called 3 times, expected at most 1');

        $this->assertCallCount('filo_trait_repo_find', atMost: 1, callable: fn (): array => filo_trait_list(3));
    }

    public function testAssertNoCalls(): void
    {
        $this->assertNoCalls('filo_trait_repo_find', fn (): array => filo_trait_list(0));

        $this->expectException(AssertionFailedError::class);
        $this->assertNoCalls('filo_trait_repo_find', fn (): array => filo_trait_list(1));
    }
}

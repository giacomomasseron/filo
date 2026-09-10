<?php

declare(strict_types=1);

namespace Filo\Testing\PHPUnit;

use Filo\Collector;
use Filo\Testing\Recorder;
use Filo\Testing\Trace;
use Filo\Testing\Traced;
use Filo\Tracer;
use InvalidArgumentException;
use PHPUnit\Event\Code\TestMethod;
use PHPUnit\Event\Test\Errored;
use PHPUnit\Event\Test\ErroredSubscriber;
use PHPUnit\Event\Test\Failed;
use PHPUnit\Event\Test\FailedSubscriber;
use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;
use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;
use ReflectionClass;
use ReflectionMethod;

/**
 * Writes <project>/.filo/traces/tests/<Class>__<method>.json for every
 * failing test and every test carrying #[Traced]. PHPUnit >= 10 event API;
 * Pest 2/3 run on PHPUnit 10/11 so the same class serves both.
 *
 * Register in phpunit.xml:
 *   <extensions><bootstrap class="Filo\Testing\PHPUnit\TraceExtension"/></extensions>
 *
 * Optional <parameter name="threshold" value="200"/> sets a global per-test
 * limit in ms, enforced by tests that use Filo\Testing\EnforcesThreshold.
 *
 * No-op when filo is not bootstrapped.
 */
final class TraceExtension implements Extension
{
    // Static so EnforcesThreshold can read them from inside the running test.
    private static bool $active      = false;
    private static ?float $threshold = null;
    private static int $mark         = 0;
    private static int $start        = 0;

    private bool $failed = false;

    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        if (!Recorder::enabled()) {
            return;
        }

        Tracer::suppressShutdownFlush();

        $facade->registerSubscribers(
            new class($this) implements PreparationStartedSubscriber {
                public function __construct(private readonly TraceExtension $ext) {}
                public function notify(PreparationStarted $event): void { $this->ext->onStart(); }
            },
            new class($this) implements FailedSubscriber {
                public function __construct(private readonly TraceExtension $ext) {}
                public function notify(Failed $event): void { $this->ext->onFailed(); }
            },
            new class($this) implements ErroredSubscriber {
                public function __construct(private readonly TraceExtension $ext) {}
                public function notify(Errored $event): void { $this->ext->onFailed(); }
            },
            new class($this) implements FinishedSubscriber {
                public function __construct(private readonly TraceExtension $ext) {}
                public function notify(Finished $event): void { $this->ext->onFinished($event); }
            },
        );
        self::$active = true;

        // Parsed last: a bad value throws, which PHPUnit reports as
        // "Bootstrapping of extension ... failed: ..." while the subscribers
        // registered above keep writing artifacts.
        if ($parameters->has('threshold')) {
            self::$threshold = self::parseThreshold($parameters->get('threshold'));
        }
    }

    /**
     * Global per-test limit in ms from the `threshold` parameter; null = none.
     *
     * @internal read by EnforcesThreshold
     */
    public static function threshold(): ?float
    {
        return self::$threshold;
    }

    /**
     * The running test so far: its events and wall time since preparation
     * started. Null when the extension isn't active.
     *
     * @internal read by EnforcesThreshold
     */
    public static function currentTest(): ?Trace
    {
        if (!self::$active) {
            return null;
        }

        return new Trace(Collector::since(self::$mark), Collector::now() - self::$start, null, true, Collector::capped());
    }

    /** @internal */
    public function onStart(): void
    {
        // Reset per test: without this the collector grows across the whole
        // suite and, once capped, silently drops every later event.
        // (Safe: the shutdown flush is suppressed; Debugger state is untouched.)
        Collector::begin();
        self::$mark   = Collector::mark();
        self::$start  = Collector::now();
        $this->failed = false;
    }

    /** @internal */
    public function onFailed(): void
    {
        $this->failed = true;
    }

    /** @internal */
    public function onFinished(Finished $event): void
    {
        $test = $event->test();
        if (!$test instanceof TestMethod) {
            return;
        }

        $traced = $this->failed || self::hasTracedAttribute($test->className(), $test->methodName());
        if (!$traced) {
            return;
        }

        $dataset = $test->testData()->hasDataFromDataProvider()
            ? (string) $test->testData()->dataFromDataProvider()->dataSetName()
            : null;

        TestArtifact::write(
            Tracer::$projectRoot,
            $test->className(),
            $test->methodName(),
            $dataset,
            $this->failed ? 'failed' : 'traced',
            Collector::since(self::$mark),
            Collector::now() - self::$start,
            Collector::capped(),
        );
    }

    private static function parseThreshold(string $raw): float
    {
        if (!is_numeric($raw) || (float) $raw <= 0) {
            throw new InvalidArgumentException(sprintf("filo: threshold must be a positive number of milliseconds, got '%s'", $raw));
        }

        return (float) $raw;
    }

    private static function hasTracedAttribute(string $class, string $method): bool
    {
        if (!class_exists($class)) {
            return false;
        }
        $rc = new ReflectionClass($class);
        if ($rc->getAttributes(Traced::class) !== []) {
            return true;
        }
        if (!$rc->hasMethod($method)) {
            return false;
        }

        return (new ReflectionMethod($class, $method))->getAttributes(Traced::class) !== [];
    }
}

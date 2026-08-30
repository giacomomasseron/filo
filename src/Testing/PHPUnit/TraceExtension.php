<?php

declare(strict_types=1);

namespace Filo\Testing\PHPUnit;

use Filo\Collector;
use Filo\Testing\Recorder;
use Filo\Testing\Traced;
use Filo\Tracer;
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
 * No-op when filo is not bootstrapped.
 */
final class TraceExtension implements Extension
{
    private int $mark   = 0;
    private int $start  = 0;
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
    }

    /** @internal */
    public function onStart(): void
    {
        $this->mark   = Collector::mark();
        $this->start  = hrtime(true);
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
            Collector::since($this->mark),
            hrtime(true) - $this->start,
        );
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

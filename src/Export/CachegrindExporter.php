<?php

declare(strict_types=1);

namespace Filo\Export;

/**
 * The callgrind format Xdebug's profiler writes (cachegrind.out.*), which
 * PhpStorm (Tools > Analyze Xdebug Profiler Snapshot), KCachegrind,
 * QCachegrind and Webgrind open.
 *
 * It sums a trace up per function, the way a profiler does: each function's
 * self time and, per function it calls, how often and for how long in
 * total. Times are in 10 ns units, as Xdebug 3 writes them. Calls without a
 * traced caller hang off {main}, whose self time is the rest of the trace,
 * so the total is the trace's duration.
 *
 * filo records memory only at a call's entry, which isn't a cost, so only
 * time is exported. It doesn't record where a call was made from either, so
 * a call's position is its caller's first line.
 *
 * @internal
 * @phpstan-import-type Event from TraceFile
 * @phpstan-type Function array{file: string, line: int, self: int, calls: array<string, array{count: int, time: int}>}
 */
final class CachegrindExporter implements Exporter
{
    private const MAIN = '{main}';

    public function format(): string
    {
        return 'cachegrind';
    }

    public function fileName(TraceFile $trace): string
    {
        return 'cachegrind.out.' . basename($trace->name, '.json');
    }

    public function openWith(): string
    {
        return 'PhpStorm (Tools > Analyze Xdebug Profiler Snapshot), KCachegrind or QCachegrind';
    }

    public function write(TraceFile $trace, $out): void
    {
        $functions = $this->functions($trace, new CallTree($trace));

        $header = "version: 1\ncreator: filo\ncmd: {$this->oneLine($trace->label())}\npart: 1\npositions: line\n\n"
            . "events: Time_(10ns)\nsummary: {$this->ticks($trace->duration)}\n\n";
        if ($trace->capped) {
            $header .= "# filo stopped recording part-way (capped): the calls after that are missing\n\n";
        }
        fwrite($out, $header);

        $files = [];
        $names = [];
        foreach ($functions as $name => $function) {
            $block = "fl={$this->ref($files, $function['file'])}\nfn={$this->ref($names, $name)}\n"
                . "{$function['line']} {$this->ticks($function['self'])}\n";
            foreach ($function['calls'] as $callee => $calls) {
                $block .= "cfl={$this->ref($files, $functions[$callee]['file'])}\ncfn={$this->ref($names, $callee)}\n"
                    . "calls={$calls['count']} {$functions[$callee]['line']}\n"
                    . "{$function['line']} {$this->ticks($calls['time'])}\n";
            }
            fwrite($out, $block . "\n");
        }
    }

    /**
     * Per function, {main} first and then in the order they ran: where it
     * is, its self time, and per function it calls, the count and the total
     * time of those calls.
     *
     * @return array<string, Function>
     */
    private function functions(TraceFile $trace, CallTree $tree): array
    {
        $functions = [self::MAIN => $this->main()];
        foreach ($trace->events as $event) {
            $id     = $event['i'];
            $fn     = $event['fn'];
            $caller = $tree->caller($id);

            $callee          = $functions[$fn] ?? $this->function($event);
            $callee['self'] += $tree->selfTime($id);
            $functions[$fn]  = $callee;

            // Read after the callee's update: a function may call itself.
            if ($caller === null) {
                $from   = self::MAIN;
                $source = $functions[self::MAIN] ?? $this->main();
            } else {
                $from   = $tree->call($caller)['fn'];
                $source = $functions[$from] ?? $this->function($tree->call($caller));
            }
            $calls                = $source['calls'][$fn] ?? ['count' => 0, 'time' => 0];
            $source['calls'][$fn] = ['count' => $calls['count'] + 1, 'time' => $calls['time'] + $tree->duration($id)];
            $functions[$from]     = $source;
        }

        // {main}'s self time is what no traced call accounts for.
        $main                  = $functions[self::MAIN] ?? $this->main();
        $main['self']          = max(0, $trace->duration - $tree->rootTime());
        $functions[self::MAIN] = $main;

        return $functions;
    }

    /** @return Function {main}, which the calls without a traced caller hang off */
    private function main(): array
    {
        return ['file' => 'php:internal', 'line' => 0, 'self' => 0, 'calls' => []];
    }

    /**
     * @param Event $event
     * @return Function
     */
    private function function(array $event): array
    {
        return ['file' => $event['file'], 'line' => $event['line'], 'self' => 0, 'calls' => []];
    }

    /**
     * A file or function name, compressed as callgrind allows: "(n) name"
     * the first time, "(n)" after that.
     *
     * @param array<string, int> $ids
     */
    private function ref(array &$ids, string $name): string
    {
        if (isset($ids[$name])) {
            return '(' . $ids[$name] . ')';
        }
        $ids[$name] = count($ids) + 1;

        return '(' . $ids[$name] . ') ' . $this->oneLine($name);
    }

    /** Nanoseconds in Xdebug 3's 10 ns units. */
    private function ticks(int $ns): int
    {
        return (int) round($ns / 10);
    }

    private function oneLine(string $text): string
    {
        return str_replace(["\r\n", "\r", "\n"], ' ', $text);
    }
}

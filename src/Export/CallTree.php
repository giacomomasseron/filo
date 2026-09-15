<?php

declare(strict_types=1);

namespace Filo\Export;

/**
 * A trace's calls as the tree every format needs, built the way the viewer
 * builds it (server/ui), so an export and the viewer agree:
 *
 *  - A call hangs under its caller (`p`). One whose caller isn't in the
 *    trace (a root, or one recorded after the trace was cut short) hangs
 *    off the top.
 *  - A call that lies within the span of an earlier call of the same
 *    caller is nested under that call: the trace flattened the chain.
 *  - Children are in the order they ran, and a call's self time is its
 *    duration minus the time its children cover (overlaps counted once),
 *    never below 0.
 *
 * @internal
 * @phpstan-import-type Event from TraceFile
 */
final class CallTree
{
    /** @var array<int, Event> */
    private array $calls = [];

    /** @var array<int, list<int>> call id => its children's ids, in the order they ran */
    private array $children = [];

    /** @var array<int, int> call id => its caller's id */
    private array $callers = [];

    /** @var list<int> */
    private array $roots = [];

    public function __construct(TraceFile $trace)
    {
        foreach ($trace->events as $event) {
            $this->calls[$event['i']] = $event;
        }
        $roots = [];
        foreach ($this->calls as $id => $event) {
            if ($event['p'] !== $id && isset($this->calls[$event['p']])) {
                $this->children[$event['p']][] = $id;
            } else {
                $roots[] = $id;
            }
        }

        // Level by level, without recursion: a call stack can be deep.
        $this->roots = $this->nest($roots);
        $pending     = $this->roots;
        while ($pending !== []) {
            $id = array_pop($pending);
            if (isset($this->children[$id])) {
                $this->children[$id] = $this->nest($this->children[$id]);
                foreach ($this->children[$id] as $child) {
                    $this->callers[$child] = $id;
                    $pending[]             = $child;
                }
            }
        }
    }

    /** @return list<int> the calls without a caller in the trace, in the order they ran */
    public function roots(): array
    {
        return $this->roots;
    }

    /** @return list<int> the calls $id made, in the order they ran */
    public function children(int $id): array
    {
        return $this->children[$id] ?? [];
    }

    /** @return Event */
    public function call(int $id): array
    {
        return $this->calls[$id];
    }

    /** The call that made $id, or null for a root. */
    public function caller(int $id): ?int
    {
        return $this->callers[$id] ?? null;
    }

    /** Nanoseconds from the call's start to its end. */
    public function duration(int $id): int
    {
        return max(0, $this->calls[$id]['e'] - $this->calls[$id]['s']);
    }

    /** Nanoseconds spent in the call itself, not in the calls it made. */
    public function selfTime(int $id): int
    {
        return max(0, $this->duration($id) - $this->covered($this->children($id)));
    }

    /** Nanoseconds the calls without a caller cover. */
    public function rootTime(): int
    {
        return $this->covered($this->roots);
    }

    /**
     * Orders one caller's calls as they ran (a longer one first when two
     * start together) and nests each call that lies within an earlier one's
     * span under it.
     *
     * @param list<int> $ids
     * @return list<int> the calls left at this level
     */
    private function nest(array $ids): array
    {
        usort($ids, fn (int $a, int $b): int => [$this->calls[$a]['s'], $this->calls[$b]['e'], $a]
            <=> [$this->calls[$b]['s'], $this->calls[$a]['e'], $b]);

        $level = [];
        $open  = [];
        foreach ($ids as $id) {
            while ($open !== [] && $this->calls[$id]['s'] >= $this->calls[$open[count($open) - 1]]['e']) {
                array_pop($open);
            }
            if ($open !== [] && $this->calls[$id]['e'] <= $this->calls[$open[count($open) - 1]]['e']) {
                $this->children[$open[count($open) - 1]][] = $id;
            } else {
                $level[] = $id;
            }
            $open[] = $id;
        }

        return $level;
    }

    /**
     * Nanoseconds $ids cover together, overlaps counted once.
     *
     * @param list<int> $ids in the order they ran
     */
    private function covered(array $ids): int
    {
        $covered = 0;
        $from    = null;
        $to      = 0;
        foreach ($ids as $id) {
            $start = $this->calls[$id]['s'];
            $end   = max($start, $this->calls[$id]['e']);
            if ($from === null) {
                [$from, $to] = [$start, $end];
            } elseif ($start <= $to) {
                $to = max($to, $end);
            } else {
                $covered    += $to - $from;
                [$from, $to] = [$start, $end];
            }
        }

        return $from === null ? $covered : $covered + $to - $from;
    }
}

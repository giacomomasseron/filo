<?php

declare(strict_types=1);

namespace Filo\Testing;

/**
 * Immutable, in-memory trace of one captured closure. Same event rows as
 * the on-disk trace format (README "Trace format"), plus query helpers.
 */
final class Trace
{
    /** @param list<array{i:int,p:int,fn:string,file:string,line:int,s:int,e:int,m:int}> $events */
    public function __construct(
        private readonly array $events,
        private readonly int $wallNs,
        private readonly mixed $result = null,
        private readonly bool $enabled = true,
        private readonly bool $capped = false,
    ) {
    }

    public function wallMs(): float
    {
        return $this->wallNs / 1e6;
    }

    public function result(): mixed
    {
        return $this->result;
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    /** True when the collector dropped events during this capture. */
    public function capped(): bool
    {
        return $this->capped;
    }

    /** @return list<array{i:int,p:int,fn:string,file:string,line:int,s:int,e:int,m:int}> */
    public function events(): array
    {
        return $this->events;
    }

    public function calls(string $fn): int
    {
        $this->assertQueryable();
        $n = 0;
        foreach ($this->events as $e) {
            if (self::matches($fn, $e['fn'])) {
                $n++;
            }
        }

        return $n;
    }

    public function inclusiveMs(string $fn): float
    {
        $this->assertQueryable();
        $ns = 0;
        foreach ($this->events as $e) {
            if (self::matches($fn, $e['fn'])) {
                $ns += $e['e'] - $e['s'];
            }
        }

        return $ns / 1e6;
    }

    public function selfMs(string $fn): float
    {
        $this->assertQueryable();
        $self = $this->selfNsById();
        $ns   = 0;
        foreach ($this->events as $e) {
            if (self::matches($fn, $e['fn'])) {
                $ns += $self[$e['i']];
            }
        }

        return $ns / 1e6;
    }

    /** @return list<string> distinct names, first-seen order */
    public function functions(): array
    {
        $seen = [];
        foreach ($this->events as $e) {
            $seen[$e['fn']] = true;
        }

        return array_keys($seen);
    }

    /** @return list<array{fn:string,selfMs:float,calls:int}> */
    public function slowestSelf(int $n = 3): array
    {
        $self = $this->selfNsById();
        $agg  = [];
        foreach ($this->events as $e) {
            $agg[$e['fn']] ??= ['fn' => $e['fn'], 'selfNs' => 0, 'calls' => 0];
            $agg[$e['fn']]['selfNs'] += $self[$e['i']];
            $agg[$e['fn']]['calls']++;
        }
        usort($agg, static fn (array $a, array $b): int => $b['selfNs'] <=> $a['selfNs']);

        return array_map(
            static fn (array $r): array => ['fn' => $r['fn'], 'selfMs' => $r['selfNs'] / 1e6, 'calls' => $r['calls']],
            array_slice($agg, 0, $n),
        );
    }

    /**
     * Trace format v1 — openable in the viewer.
     *
     * @return array{version: int, ts: string, duration: int, capped: bool, context: array<string, mixed>, events: list<array{i:int,p:int,fn:string,file:string,line:int,s:int,e:int,m:int}>}
     */
    public function toArray(): array
    {
        return [
            'version'  => 1,
            'ts'       => date('c'),
            'duration' => $this->wallNs,
            'capped'   => $this->capped,
            'context'  => ['sapi' => PHP_SAPI, 'capture' => true],
            'events'   => $this->events,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }

    /** Exact match, or prefix match when the pattern ends with '*'. */
    public static function matches(string $pattern, string $fn): bool
    {
        if (str_ends_with($pattern, '*')) {
            return str_starts_with($fn, substr($pattern, 0, -1));
        }

        return $pattern === $fn;
    }

    /** @return array<int, int> event id => self ns */
    private function selfNsById(): array
    {
        $self = [];
        foreach ($this->events as $e) {
            $self[$e['i']] = $e['e'] - $e['s'];
        }
        foreach ($this->events as $e) {
            if ($e['p'] >= 0 && isset($self[$e['p']])) {
                $self[$e['p']] -= $e['e'] - $e['s'];
            }
        }

        return $self;
    }

    private function assertQueryable(): void
    {
        if (!$this->enabled) {
            throw FiloNotEnabledException::create();
        }
        if ($this->capped) {
            throw TraceCappedException::create();
        }
    }
}

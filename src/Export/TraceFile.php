<?php

declare(strict_types=1);

namespace Filo\Export;

/**
 * A trace (format v1, docs/trace-v1.schema.json) read and checked for
 * exporting: every event has its eight fields, typed as the format says.
 *
 * @internal
 * @phpstan-type Event array{i: int, p: int, fn: string, file: string, line: int, s: int, e: int, m: int}
 */
final class TraceFile
{
    /**
     * @param array<mixed> $context where the trace came from (method and uri, argv, test)
     * @param list<Event>  $events  one per call, in call order
     */
    public function __construct(
        public readonly string $name,
        public readonly int $duration,
        public readonly bool $capped,
        public readonly array $context,
        public readonly array $events,
    ) {
    }

    /** @throws ExportException when $path can't be read or isn't a trace */
    public static function fromFile(string $path, ?string $name = null): self
    {
        $name ??= basename($path);
        $json   = is_file($path) && is_readable($path) ? file_get_contents($path) : false;
        if ($json === false) {
            throw ExportException::unreadable($path);
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw ExportException::notATrace($name, json_last_error() === JSON_ERROR_NONE ? 'not a JSON object' : json_last_error_msg());
        }

        return self::fromArray($data, $name);
    }

    /**
     * @param array<mixed> $data a decoded trace
     * @throws ExportException when $data isn't a v1 trace
     */
    public static function fromArray(array $data, string $name): self
    {
        if (($data['version'] ?? null) !== 1) {
            throw ExportException::notATrace($name, 'no "version": 1');
        }
        if (!isset($data['events']) || !is_array($data['events']) || !array_is_list($data['events'])) {
            throw ExportException::notATrace($name, 'no "events" list');
        }

        $events = [];
        foreach ($data['events'] as $n => $event) {
            if (!is_array($event) || !isset($event['i'], $event['p'], $event['fn'], $event['s'], $event['e'])) {
                throw ExportException::notATrace($name, "event #{$n} lacks i, p, fn, s or e");
            }
            $events[] = [
                'i'    => (int) $event['i'],
                'p'    => (int) $event['p'],
                'fn'   => (string) $event['fn'],
                'file' => (string) ($event['file'] ?? ''),
                'line' => (int) ($event['line'] ?? 0),
                's'    => (int) $event['s'],
                'e'    => (int) $event['e'],
                'm'    => (int) ($event['m'] ?? 0),
            ];
        }
        $end = $events === [] ? 0 : max(array_column($events, 'e'));

        return new self(
            $name,
            max((int) ($data['duration'] ?? 0), $end),
            (bool) ($data['capped'] ?? false),
            isset($data['context']) && is_array($data['context']) ? $data['context'] : [],
            $events,
        );
    }

    /** What ran: the test, the request ("GET /orders") or the command line. */
    public function label(): string
    {
        $context = $this->context;
        if (isset($context['test']) && is_string($context['test'])) {
            return $context['test'];
        }
        if (isset($context['uri']) && is_string($context['uri'])) {
            $method = isset($context['method']) && is_string($context['method']) ? $context['method'] . ' ' : '';

            return $method . $context['uri'];
        }
        if (isset($context['argv']) && is_array($context['argv'])) {
            return implode(' ', array_filter($context['argv'], 'is_string'));
        }

        return $this->name;
    }
}

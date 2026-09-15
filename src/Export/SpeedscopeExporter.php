<?php

declare(strict_types=1);

namespace Filo\Export;

/**
 * speedscope's "evented" format (https://www.speedscope.app): the whole
 * timeline as a flame chart, plus its left-heavy and sandwich views. The
 * file opens in the browser at speedscope.app and stays on your machine.
 *
 * Each call opens at its start and closes at its end, and speedscope needs
 * those nested like a stack: a call is kept within its caller and after
 * the call before it, so the rare one that outlives its caller (a generator
 * finished by someone else) is trimmed to fit.
 *
 * @internal
 */
final class SpeedscopeExporter implements Exporter
{
    private const FLUSH_BYTES = 65536;

    public function format(): string
    {
        return 'speedscope';
    }

    public function fileName(TraceFile $trace): string
    {
        return basename($trace->name, '.json') . '.speedscope.json';
    }

    public function openWith(): string
    {
        return 'https://www.speedscope.app (drop the file on it: it stays on your machine)';
    }

    public function write(TraceFile $trace, $out): void
    {
        $frames = [];
        $index  = [];
        foreach ($trace->events as $event) {
            if (!isset($index[$event['fn']])) {
                $index[$event['fn']] = count($frames);
                $frames[]            = ['name' => $event['fn'], 'file' => $event['file'], 'line' => $event['line']];
            }
        }

        fwrite($out, '{"$schema":"https://www.speedscope.app/file-format-schema.json","exporter":"filo"'
            . ',"name":' . $this->json($trace->label()) . ',"activeProfileIndex":0'
            . ',"shared":{"frames":' . $this->json($frames) . '}'
            . ',"profiles":[{"type":"evented","name":' . $this->json($trace->name)
            . ',"unit":"nanoseconds","startValue":0,"endValue":' . $trace->duration . ',"events":[');
        $this->events(new CallTree($trace), $index, $trace->duration, $out);
        fwrite($out, ']}]}');
    }

    /**
     * Writes the open and close events depth first, without recursion (a
     * call stack can be deep), buffered into large writes.
     *
     * @param array<string, int> $index frame index per function
     * @param resource           $out
     */
    private function events(CallTree $tree, array $index, int $end, $out): void
    {
        $buffer = '';
        $comma  = '';
        // Per level: the calls left to open, where the next may start at the
        // earliest and end at the latest, and the call to close when done.
        /** @var list<array{ids: list<int>, next: int, from: int, to: int, close: array{int, int}|null}> $levels */
        $levels = [['ids' => $tree->roots(), 'next' => 0, 'from' => 0, 'to' => $end, 'close' => null]];
        while ($levels !== []) {
            $top = count($levels) - 1;
            if ($levels[$top]['next'] >= count($levels[$top]['ids'])) {
                $close = $levels[$top]['close'];
                array_pop($levels);
                if ($close !== null) {
                    $buffer .= $comma . '{"type":"C","frame":' . $close[0] . ',"at":' . $close[1] . '}';
                    $comma   = ',';
                }
            } else {
                $level = $levels[$top];
                $id    = $level['ids'][$level['next']];
                $call  = $tree->call($id);
                $start = min(max($call['s'], $level['from']), $level['to']);
                $stop  = max(min($call['e'], $level['to']), $start);
                $frame = $index[$call['fn']];

                $buffer .= $comma . '{"type":"O","frame":' . $frame . ',"at":' . $start . '}';
                $comma   = ',';
                $levels[$top]['next']++;
                $levels[$top]['from'] = $stop;
                $levels[]             = ['ids' => $tree->children($id), 'next' => 0, 'from' => $start, 'to' => $stop, 'close' => [$frame, $stop]];
            }
            if (strlen($buffer) >= self::FLUSH_BYTES) {
                fwrite($out, $buffer);
                $buffer = '';
            }
        }
        fwrite($out, $buffer);
    }

    private function json(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}

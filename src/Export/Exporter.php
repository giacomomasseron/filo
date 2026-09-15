<?php

declare(strict_types=1);

namespace Filo\Export;

/**
 * A trace format another tool opens. `filo export --format=<format()>`
 * picks one from Exporters; each format is one class.
 *
 * @internal The formats and `filo export` are public (README "Exporting
 *           traces"); this interface is not.
 */
interface Exporter
{
    /** The name `filo export --format=` takes, e.g. "cachegrind". */
    public function format(): string;

    /** The file name to give $trace's export, e.g. "cachegrind.out.<trace>". */
    public function fileName(TraceFile $trace): string;

    /** The tools that open this format, for the CLI to suggest. */
    public function openWith(): string;

    /**
     * Writes $trace in this format to $out as it goes, so an export is never
     * held in memory whole.
     *
     * @param resource $out a writable stream
     */
    public function write(TraceFile $trace, $out): void;
}

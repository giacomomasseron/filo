<?php

declare(strict_types=1);

namespace Filo\Export;

/**
 * The formats `filo export` knows, by name. A new format is one Exporter
 * class, listed in builtIn().
 *
 * @internal
 */
final class Exporters
{
    /** @var array<string, Exporter> */
    private array $byFormat = [];

    public function __construct(Exporter ...$exporters)
    {
        foreach ($exporters as $exporter) {
            $this->byFormat[$exporter->format()] = $exporter;
        }
    }

    public static function builtIn(): self
    {
        return new self(new CachegrindExporter(), new SpeedscopeExporter());
    }

    /** @return list<string> */
    public function formats(): array
    {
        return array_keys($this->byFormat);
    }

    /** @throws ExportException for a format it doesn't know */
    public function get(string $format): Exporter
    {
        return $this->byFormat[$format] ?? throw ExportException::unknownFormat($format, $this->formats());
    }
}

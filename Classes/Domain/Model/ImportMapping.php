<?php

declare(strict_types=1);

namespace T3x\StaticHtmlImporter\Domain\Model;

/**
 * One mapping rule loaded from a YAML file under Resources/Private/Mapping/.
 *
 * `selector` is an optional structural hint (CSS or simple tag/class match).
 * `fields` is keyed by tt_content column name and holds FieldDefinition
 * objects. The runtime type stays `array` for cheap construction; consumers
 * should rely on the @param hint.
 */
final readonly class ImportMapping
{
    /**
     * @param array<string, FieldDefinition> $fields field definitions keyed by tt_content column
     */
    public function __construct(
        public string $cType,
        public ?string $selector = null,
        public array $fields = [],
    ) {
    }
}

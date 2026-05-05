<?php

declare(strict_types=1);

namespace T3x\StaticHtmlImporter\Domain\Model;

/**
 * One mapping rule loaded from a YAML file under Resources/Private/Mapping/.
 *
 * `selector` is an optional structural hint (CSS or simple tag/class match).
 * `fields` shape stays untyped at this stage; a typed FieldDefinition is
 * introduced alongside the AiClassifier in issue #5.
 */
final readonly class ImportMapping
{
    /**
     * @param array<string, mixed> $fields field definitions keyed by tt_content column
     */
    public function __construct(
        public string $cType,
        public ?string $selector = null,
        public array $fields = [],
    ) {
    }
}

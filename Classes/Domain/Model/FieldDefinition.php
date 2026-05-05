<?php

declare(strict_types=1);

namespace T3x\StaticHtmlImporter\Domain\Model;

/**
 * One field declared in a YAML mapping (`Resources/Private/Mapping/*.yaml`).
 *
 * `name` is the tt_content column or sub-field, `description` is the
 * human-readable hint sent to the LLM when deterministic extraction misses,
 * `type` constrains how the value is later coerced.
 */
final readonly class FieldDefinition
{
    public function __construct(
        public string $name,
        public string $description,
        public string $type = 'string',
    ) {
    }
}

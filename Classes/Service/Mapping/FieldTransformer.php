<?php

declare(strict_types=1);

namespace T3x\StaticHtmlImporter\Service\Mapping;

use RuntimeException;
use T3x\StaticHtmlImporter\Domain\Model\ContentBlock;
use T3x\StaticHtmlImporter\Domain\Model\FieldDefinition;
use T3x\StaticHtmlImporter\Domain\Model\ImportMapping;

/**
 * Stub. Coerces an extracted HTML fragment into the typed tt_content value.
 *
 * @todo Implement in the next phase. See FieldTransformerInterface for the
 *       coercion strategy per FieldDefinition::$type.
 */
final class FieldTransformer implements FieldTransformerInterface
{
    public function transform(ContentBlock $block, ImportMapping $mapping, FieldDefinition $field): ?string
    {
        unset($block, $mapping, $field);
        throw new RuntimeException(
            'FieldTransformer is a stub. Implement in the next phase (see PROJECT_BRIEF.md).',
        );
    }
}

<?php

declare(strict_types=1);

namespace T3x\StaticHtmlImporter\Service\Mapping;

use T3x\StaticHtmlImporter\Domain\Model\ContentBlock;
use T3x\StaticHtmlImporter\Domain\Model\FieldDefinition;
use T3x\StaticHtmlImporter\Domain\Model\ImportMapping;

/**
 * Coerces a raw HTML fragment value into a tt_content column value.
 *
 * @todo Implement in the next phase. Type-aware coercion: 'string' trims and
 *       strips tags, 'html' goes through TYPO3's RteHtmlParser, 'int' parses
 *       numbers, 'date' resolves common date formats. Falls back to the
 *       AiClassifier when deterministic extraction yields null.
 */
interface FieldTransformerInterface
{
    public function transform(ContentBlock $block, ImportMapping $mapping, FieldDefinition $field): ?string;
}

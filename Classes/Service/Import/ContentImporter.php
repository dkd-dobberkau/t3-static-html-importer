<?php

declare(strict_types=1);

namespace T3x\StaticHtmlImporter\Service\Import;

use RuntimeException;
use T3x\StaticHtmlImporter\Domain\Model\ContentBlock;
use T3x\StaticHtmlImporter\Domain\Model\ImportMapping;

/**
 * Stub. Persists tt_content records via DataHandler.
 *
 * @todo Implement in the next phase. See ContentImporterInterface.
 */
final class ContentImporter implements ContentImporterInterface
{
    public function import(ContentBlock $block, ImportMapping $mapping, int $targetPid): int
    {
        unset($block, $mapping, $targetPid);
        throw new RuntimeException(
            'ContentImporter is a stub. Implement in the next phase (see PROJECT_BRIEF.md).',
        );
    }
}

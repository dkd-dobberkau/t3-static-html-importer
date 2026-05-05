<?php

declare(strict_types=1);

namespace T3x\StaticHtmlImporter\Service\Import;

use T3x\StaticHtmlImporter\Domain\Model\ContentBlock;
use T3x\StaticHtmlImporter\Domain\Model\ImportMapping;

/**
 * Persists an analysed and mapped block as a tt_content record.
 *
 * @todo Implement in the next phase. Use the configured DataHandler with
 *       dry-run support, surface validation errors as a CSV review report,
 *       and dedupe by ContentBlock::$id so re-runs are idempotent.
 */
interface ContentImporterInterface
{
    /**
     * Returns the persisted tt_content uid.
     */
    public function import(ContentBlock $block, ImportMapping $mapping, int $targetPid): int;
}

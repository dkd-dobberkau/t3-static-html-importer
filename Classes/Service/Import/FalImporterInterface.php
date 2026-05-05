<?php

declare(strict_types=1);

namespace T3x\StaticHtmlImporter\Service\Import;

/**
 * Imports binary assets (images, media) into TYPO3's FAL.
 *
 * @todo Implement in the next phase. Dedupe by SHA1 of file contents (per
 *       PROJECT_BRIEF.md), enrich sys_file_metadata via AiClassifier when
 *       configured, and place files under a configurable storage folder.
 */
interface FalImporterInterface
{
    /**
     * Returns the FAL file uid of the imported asset.
     */
    public function importFile(string $sourcePath, string $targetFolder): int;
}

<?php

declare(strict_types=1);

namespace T3x\StaticHtmlImporter\Service\Import;

use RuntimeException;

/**
 * Stub. Imports binary assets into FAL with SHA1-based dedupe.
 *
 * @todo Implement in the next phase. See FalImporterInterface.
 */
final class FalImporter implements FalImporterInterface
{
    public function importFile(string $sourcePath, string $targetFolder): int
    {
        unset($sourcePath, $targetFolder);
        throw new RuntimeException(
            'FalImporter is a stub. Implement in the next phase (see PROJECT_BRIEF.md).',
        );
    }
}

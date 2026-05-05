<?php

declare(strict_types=1);

namespace T3x\StaticHtmlImporter\Service\Template;

use T3x\StaticHtmlImporter\Domain\Model\ContentBlock;

/**
 * Generates Fluid partials, layouts and templates from analysed blocks.
 *
 * @todo Implement in the next phase. Should write under
 *       `Resources/Private/Templates/`, `Layouts/`, `Partials/`. Idempotent
 *       writes (skip if BlockHasher hash unchanged) and a dry-run option are
 *       mandatory; recurring structures should be distilled into shared
 *       partials per PROJECT_BRIEF.md (stage 3, "Template Extractor").
 */
interface FluidPartialGeneratorInterface
{
    /**
     * @param  list<ContentBlock> $blocks
     * @return list<string>       paths of files written, relative to $targetRoot
     */
    public function generate(array $blocks, string $targetRoot): array;
}

<?php

declare(strict_types=1);

namespace T3x\StaticHtmlImporter\Service\Template;

use T3x\StaticHtmlImporter\Domain\Model\ContentBlock;

/**
 * Generates Fluid partials, layouts and templates from analysed blocks.
 *
 * @todo Field-level placeholder extraction (replacing block text with
 *       `{block.title}` etc.) is layered on top by FieldTransformer (issue #12).
 */
interface FluidPartialGeneratorInterface
{
    /**
     * @param  list<ContentBlock> $blocks
     * @param  bool                $dryRun  list paths without writing or updating the manifest
     * @return list<string>        paths of files that were (or would be) written, relative to $targetRoot
     */
    public function generate(array $blocks, string $targetRoot, bool $dryRun = false): array;
}

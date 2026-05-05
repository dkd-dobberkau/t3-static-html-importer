<?php

declare(strict_types=1);

namespace T3x\StaticHtmlImporter\Service\Template;

use RuntimeException;

/**
 * Stub. Distils repeating block structures into Fluid partials.
 *
 * @todo Implement in the next phase. Wire into TemplatesCommand once a
 *       deterministic dedupe (BlockHasher) and a partial-naming strategy
 *       are in place.
 */
final class FluidPartialGenerator implements FluidPartialGeneratorInterface
{
    /**
     * @param  list<\T3x\StaticHtmlImporter\Domain\Model\ContentBlock> $blocks
     * @return list<string>
     */
    public function generate(array $blocks, string $targetRoot): array
    {
        unset($blocks, $targetRoot);
        throw new RuntimeException(
            'FluidPartialGenerator is a stub. Implement in the next phase (see PROJECT_BRIEF.md).',
        );
    }
}

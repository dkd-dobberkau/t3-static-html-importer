<?php

declare(strict_types=1);

namespace T3x\StaticHtmlImporter\Service\Analyzer;

use T3x\StaticHtmlImporter\Domain\Model\ClassificationResult;
use T3x\StaticHtmlImporter\Domain\Model\ContentBlock;

/**
 * Pairing of a heuristic ContentBlock with the optional AI ClassificationResult.
 *
 * Convenience accessors return the AI verdict when present and fall back to
 * the heuristic top candidate otherwise. Used by AnalyzeCommand to render
 * reports without sprinkling null-checks everywhere.
 */
final readonly class AnalyzedBlock
{
    public function __construct(
        public ContentBlock $block,
        public ?ClassificationResult $classification = null,
    ) {
    }

    public function effectiveType(): string
    {
        return $this->classification?->type
            ?? $this->block->candidateTypes[0]
            ?? 'unknown';
    }

    public function effectiveConfidence(): float
    {
        return $this->classification?->confidence ?? $this->block->confidence;
    }

    public function source(): string
    {
        return $this->classification === null ? 'heuristic' : 'ai';
    }
}

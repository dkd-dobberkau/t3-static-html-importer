<?php

declare(strict_types=1);

namespace T3x\StaticHtmlImporter\Domain\Model;

use InvalidArgumentException;

/**
 * Semantic chunk extracted from a SourceDocument's DOM by the StructuralAnalyzer.
 *
 * Holds heuristic candidates only; final classification is produced by the
 * AiClassifier and stored in a separate ClassificationResult.
 */
final readonly class ContentBlock
{
    /**
     * @param list<string>          $candidateTypes CType candidates from heuristics, ordered by likelihood
     * @param array<string, mixed>  $attributes    DOM attributes captured for downstream use (id, class, data-*)
     */
    public function __construct(
        public string $id,
        public string $html,
        public string $tag,
        public array $candidateTypes,
        public float $confidence,
        public array $attributes = [],
    ) {
        if ($confidence < 0.0 || $confidence > 1.0) {
            throw new InvalidArgumentException('confidence must be between 0.0 and 1.0');
        }
    }
}

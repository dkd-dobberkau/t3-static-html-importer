<?php

declare(strict_types=1);

namespace T3x\StaticHtmlImporter\Domain\Model;

use InvalidArgumentException;

/**
 * Result of an AiClassifier classify call.
 *
 * `rationale` is short free text from the model used for review reports when
 * confidence falls below the configured threshold.
 */
final readonly class ClassificationResult
{
    public function __construct(
        public string $type,
        public float $confidence,
        public string $rationale,
    ) {
        if ($confidence < 0.0 || $confidence > 1.0) {
            throw new InvalidArgumentException('confidence must be between 0.0 and 1.0');
        }
    }
}

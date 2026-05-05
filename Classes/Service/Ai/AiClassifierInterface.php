<?php

declare(strict_types=1);

namespace T3x\StaticHtmlImporter\Service\Ai;

use T3x\StaticHtmlImporter\Domain\Model\AssetMetadata;
use T3x\StaticHtmlImporter\Domain\Model\ClassificationResult;
use T3x\StaticHtmlImporter\Domain\Model\FieldDefinition;

/**
 * Contract for AI-assisted classification, field extraction and asset enrichment.
 *
 * The production implementation wraps `B13\Aim\Ai`. AiClassifierMock provides
 * a deterministic alternative for tests so no network calls are needed.
 */
interface AiClassifierInterface
{
    /**
     * @param list<string> $candidateTypes hints from deterministic heuristics
     */
    public function classifyBlock(string $domFragment, array $candidateTypes): ClassificationResult;

    public function extractFieldValue(string $domFragment, FieldDefinition $field): ?string;

    public function enrichAssetMetadata(string $imagePath): AssetMetadata;
}

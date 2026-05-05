<?php

declare(strict_types=1);

namespace T3x\StaticHtmlImporter\Service\Ai;

use T3x\StaticHtmlImporter\Domain\Model\AssetMetadata;
use T3x\StaticHtmlImporter\Domain\Model\ClassificationResult;
use T3x\StaticHtmlImporter\Domain\Model\FieldDefinition;

/**
 * Deterministic AiClassifier replacement for tests and `--no-ai` runs.
 *
 * Picks the first candidate type, returns a fixed mid-confidence value, and
 * derives field values and asset metadata from simple substring rules so
 * tests can assert exact outputs without touching the network.
 */
final class AiClassifierMock implements AiClassifierInterface
{
    public function classifyBlock(string $domFragment, array $candidateTypes): ClassificationResult
    {
        $type = $candidateTypes[0] ?? 'unknown';
        return new ClassificationResult(
            type: $type,
            confidence: 0.5,
            rationale: sprintf('mock: picked first candidate "%s"', $type),
        );
    }

    public function extractFieldValue(string $domFragment, FieldDefinition $field): ?string
    {
        $marker = '<!--' . $field->name . ':';
        $start = strpos($domFragment, $marker);
        if ($start === false) {
            return null;
        }
        $valueStart = $start + strlen($marker);
        $end = strpos($domFragment, '-->', $valueStart);
        if ($end === false) {
            return null;
        }
        return trim(substr($domFragment, $valueStart, $end - $valueStart));
    }

    public function enrichAssetMetadata(string $imagePath): AssetMetadata
    {
        $base = pathinfo($imagePath, PATHINFO_FILENAME);
        $title = ucwords(str_replace(['-', '_'], ' ', $base));
        return new AssetMetadata(
            altText: $title,
            title: $title,
            description: null,
            tags: ['mock'],
        );
    }
}

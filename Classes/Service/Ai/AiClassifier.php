<?php

declare(strict_types=1);

namespace T3x\StaticHtmlImporter\Service\Ai;

use B13\Aim\Ai;
use B13\Aim\Response\TextResponse;
use RuntimeException;
use T3x\StaticHtmlImporter\Domain\Model\AssetMetadata;
use T3x\StaticHtmlImporter\Domain\Model\ClassificationResult;
use T3x\StaticHtmlImporter\Domain\Model\FieldDefinition;

/**
 * Production AiClassifier wrapping `B13\Aim\Ai`.
 *
 * Each entry method runs cache lookup -> AiM call -> JSON parse -> schema
 * required-key validation -> DTO hydration. classifyBlock and
 * extractFieldValue go through `Ai::text()`; enrichAssetMetadata uses
 * `Ai::vision()` so the model can actually look at the image.
 *
 * AiClassifierMock provides the deterministic alternative for tests so no
 * network calls are needed. Tests that exercise this class should mock the
 * injected `Ai` instance or run behind an integration flag.
 */
final class AiClassifier implements AiClassifierInterface
{
    private const EXTENSION_KEY = 'static_html_importer';

    private const TEXT_TEMPERATURE = 0.2;
    private const TEXT_MAX_TOKENS = 500;

    private const VISION_TEMPERATURE = 0.3;
    private const VISION_MAX_TOKENS = 200;

    public function __construct(
        private readonly Ai $aim,
        private readonly PromptLibrary $prompts,
        private readonly ResultCache $cache,
        private readonly JsonResponseExtractor $jsonExtractor,
    ) {
    }

    public function classifyBlock(string $domFragment, array $candidateTypes): ClassificationResult
    {
        $key = $this->cache->key('classifyBlock', $domFragment, ...$candidateTypes);
        $payload = $this->cache->get($key)
            ?? $this->store($key, $this->dispatchText($this->prompts->classifyBlock($domFragment, $candidateTypes)));

        return new ClassificationResult(
            type: (string)($payload['type'] ?? 'unknown'),
            confidence: $this->normaliseConfidence($payload['confidence'] ?? 0.0),
            rationale: (string)($payload['rationale'] ?? ''),
        );
    }

    public function extractFieldValue(string $domFragment, FieldDefinition $field): ?string
    {
        $key = $this->cache->key('extractField', $domFragment, $field->name, $field->type);
        $payload = $this->cache->get($key)
            ?? $this->store($key, $this->dispatchText($this->prompts->extractField($domFragment, $field)));

        $value = $payload['value'] ?? null;
        return $value === null ? null : (string)$value;
    }

    public function enrichAssetMetadata(string $imagePath): AssetMetadata
    {
        $key = $this->cache->key('enrichAsset', $imagePath);
        $payload = $this->cache->get($key)
            ?? $this->store($key, $this->dispatchVision($this->prompts->enrichAsset($imagePath), $imagePath));

        $tags = $payload['tags'] ?? [];
        return new AssetMetadata(
            altText: (string)($payload['altText'] ?? ''),
            title: (string)($payload['title'] ?? ''),
            description: isset($payload['description']) ? (string)$payload['description'] : null,
            tags: is_array($tags) ? array_values(array_map('strval', $tags)) : [],
        );
    }

    /**
     * @param  array{system: string, user: string, schema: string} $prompt
     * @return array<string, mixed>
     */
    private function dispatchText(array $prompt): array
    {
        $response = $this->aim->text(
            prompt: $prompt['user'],
            systemPrompt: $prompt['system'],
            maxTokens: self::TEXT_MAX_TOKENS,
            temperature: self::TEXT_TEMPERATURE,
            extensionKey: self::EXTENSION_KEY,
        );
        return $this->parse($response, $prompt['schema']);
    }

    /**
     * @param  array{system: string, user: string, schema: string} $prompt
     * @return array<string, mixed>
     */
    private function dispatchVision(array $prompt, string $imagePath): array
    {
        if (!is_file($imagePath) || !is_readable($imagePath)) {
            throw new RuntimeException(sprintf('Image is not readable: %s', $imagePath));
        }
        $imageData = file_get_contents($imagePath);
        if ($imageData === false) {
            throw new RuntimeException(sprintf('Cannot read image: %s', $imagePath));
        }

        $response = $this->aim->vision(
            imageData: $imageData,
            mimeType: $this->detectMimeType($imagePath),
            prompt: $prompt['user'],
            systemPrompt: $prompt['system'],
            maxTokens: self::VISION_MAX_TOKENS,
            temperature: self::VISION_TEMPERATURE,
            extensionKey: self::EXTENSION_KEY,
        );
        return $this->parse($response, $prompt['schema']);
    }

    /**
     * @return array<string, mixed>
     */
    private function parse(TextResponse $response, string $schemaJson): array
    {
        if (!$response->isSuccessful()) {
            $message = $response->errors === []
                ? 'empty response'
                : implode('; ', array_map(static fn (mixed $e): string => (string)$e, $response->errors));
            throw new RuntimeException(sprintf('AiM request failed: %s', $message));
        }
        return $this->jsonExtractor->extract($response->content, $schemaJson);
    }

    private function detectMimeType(string $imagePath): string
    {
        $info = @getimagesize($imagePath);
        if (is_array($info) && isset($info['mime']) && is_string($info['mime'])) {
            return $info['mime'];
        }
        return match (strtolower(pathinfo($imagePath, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            default => 'application/octet-stream',
        };
    }

    private function normaliseConfidence(mixed $raw): float
    {
        $value = is_numeric($raw) ? (float)$raw : 0.0;
        return max(0.0, min(1.0, $value));
    }

    /**
     * @param  array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function store(string $key, array $payload): array
    {
        $this->cache->set($key, $payload);
        return $payload;
    }
}

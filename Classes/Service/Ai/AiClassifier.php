<?php

declare(strict_types=1);

namespace T3x\StaticHtmlImporter\Service\Ai;

use B13\Aim\Ai;
use RuntimeException;
use T3x\StaticHtmlImporter\Domain\Model\AssetMetadata;
use T3x\StaticHtmlImporter\Domain\Model\ClassificationResult;
use T3x\StaticHtmlImporter\Domain\Model\FieldDefinition;

/**
 * Production AiClassifier wrapping `B13\Aim\Ai`.
 *
 * The pipeline (cache lookup → prompt build → LLM call → parse → cache write
 * → DTO hydration) is in place. The actual `B13\Aim\Ai` invocation is
 * deferred because b13/aim is alpha and its surface may shift; per
 * TASKS.md the mock is wired up first and AiM later.
 *
 * Tests inject AiClassifierMock via AiClassifierInterface.
 *
 * @todo Wire `$this->aim` calls in `dispatch()` once b13/aim's API is settled (#5).
 */
final class AiClassifier implements AiClassifierInterface
{
    public function __construct(
        private readonly Ai $aim,
        private readonly PromptLibrary $prompts,
        private readonly ResultCache $cache,
    ) {
    }

    public function classifyBlock(string $domFragment, array $candidateTypes): ClassificationResult
    {
        $key = $this->cache->key('classifyBlock', $domFragment, ...$candidateTypes);
        $payload = $this->cache->get($key)
            ?? $this->store($key, $this->dispatch($this->prompts->classifyBlock($domFragment, $candidateTypes)));

        return new ClassificationResult(
            type: (string)($payload['type'] ?? 'unknown'),
            confidence: (float)($payload['confidence'] ?? 0.0),
            rationale: (string)($payload['rationale'] ?? ''),
        );
    }

    public function extractFieldValue(string $domFragment, FieldDefinition $field): ?string
    {
        $key = $this->cache->key('extractField', $domFragment, $field->name, $field->type);
        $payload = $this->cache->get($key)
            ?? $this->store($key, $this->dispatch($this->prompts->extractField($domFragment, $field)));

        $value = $payload['value'] ?? null;
        return $value === null ? null : (string)$value;
    }

    public function enrichAssetMetadata(string $imagePath): AssetMetadata
    {
        $key = $this->cache->key('enrichAsset', $imagePath);
        $payload = $this->cache->get($key)
            ?? $this->store($key, $this->dispatch($this->prompts->enrichAsset($imagePath)));

        $tags = $payload['tags'] ?? [];
        return new AssetMetadata(
            altText: (string)($payload['altText'] ?? ''),
            title: (string)($payload['title'] ?? ''),
            description: isset($payload['description']) ? (string)$payload['description'] : null,
            tags: is_array($tags) ? array_values(array_map('strval', $tags)) : [],
        );
    }

    /**
     * @param array{system: string, user: string, schema: string} $prompt
     * @return array<string, mixed>
     */
    private function dispatch(array $prompt): array
    {
        unset($prompt);
        throw new RuntimeException(
            'AiClassifier is not yet wired to B13\\Aim\\Ai (alpha API). '
            . 'Inject AiClassifierMock for now (see issue #5).',
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function store(string $key, array $payload): array
    {
        $this->cache->set($key, $payload);
        return $payload;
    }
}

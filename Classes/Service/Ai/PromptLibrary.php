<?php

declare(strict_types=1);

namespace T3x\StaticHtmlImporter\Service\Ai;

use T3x\StaticHtmlImporter\Domain\Model\FieldDefinition;

/**
 * Central registry of LLM prompts. No prompt is allowed to live inline next to
 * its caller; everything that ends up in front of a model is assembled here so
 * it can be reviewed, A/B-tested and versioned in one place.
 *
 * Each method returns an associative array with keys:
 *   - `system`: system message,
 *   - `user`:   user message,
 *   - `schema`: JSON schema description the model is expected to honour.
 */
final class PromptLibrary
{
    /**
     * @param list<string> $candidateTypes
     * @return array{system: string, user: string, schema: string}
     */
    public function classifyBlock(string $domFragment, array $candidateTypes): array
    {
        $candidates = $candidateTypes === [] ? '(none)' : implode(', ', $candidateTypes);

        return [
            'system' => 'You classify HTML fragments into TYPO3 content types. '
                . 'Choose exactly one type. Return strict JSON only.',
            'user' => "Candidate types from heuristics: {$candidates}.\n\n"
                . "HTML fragment:\n{$domFragment}\n\n"
                . 'Pick the best matching type, score your confidence in [0.0, 1.0], '
                . 'and explain in one short sentence.',
            'schema' => '{"type":"object","required":["type","confidence","rationale"],'
                . '"properties":{"type":{"type":"string"},'
                . '"confidence":{"type":"number","minimum":0,"maximum":1},'
                . '"rationale":{"type":"string"}}}',
        ];
    }

    /**
     * @return array{system: string, user: string, schema: string}
     */
    public function extractField(string $domFragment, FieldDefinition $field): array
    {
        return [
            'system' => 'You extract one field value from an HTML fragment. '
                . 'Return strict JSON. If the value is not present, return null.',
            'user' => "Field name: {$field->name}\n"
                . "Field description: {$field->description}\n"
                . "Expected type: {$field->type}\n\n"
                . "HTML fragment:\n{$domFragment}",
            'schema' => '{"type":"object","required":["value"],'
                . '"properties":{"value":{"type":["string","null"]}}}',
        ];
    }

    /**
     * @return array{system: string, user: string, schema: string}
     */
    public function enrichAsset(string $imagePath): array
    {
        return [
            'system' => 'You generate alt text, a short title, an optional description '
                . 'and tags for an image. Be factual; do not hallucinate text that is '
                . 'not visible. Return strict JSON.',
            'user' => "Image path: {$imagePath}",
            'schema' => '{"type":"object","required":["altText","title","tags"],'
                . '"properties":{"altText":{"type":"string"},"title":{"type":"string"},'
                . '"description":{"type":["string","null"]},'
                . '"tags":{"type":"array","items":{"type":"string"}}}}',
        ];
    }
}

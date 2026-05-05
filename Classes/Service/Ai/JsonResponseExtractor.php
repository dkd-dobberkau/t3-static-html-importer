<?php

declare(strict_types=1);

namespace T3x\StaticHtmlImporter\Service\Ai;

use RuntimeException;

/**
 * Extracts a JSON object from an LLM text response.
 *
 * Models routinely wrap structured output in markdown code fences or surround
 * it with prose ("Sure, here is the JSON: ..."). This helper strips that and
 * returns the parsed associative array, then enforces the schema's `required`
 * keys list so callers can rely on them being present.
 */
final class JsonResponseExtractor
{
    /**
     * @param  string $schemaJson the JSON schema string from PromptLibrary
     * @return array<string, mixed>
     */
    public function extract(string $rawContent, string $schemaJson): array
    {
        $jsonText = $this->stripWrappers($rawContent);
        $decoded = json_decode($jsonText, true);
        if (!is_array($decoded)) {
            throw new RuntimeException(sprintf(
                'LLM response is not a JSON object. First 200 chars: %s',
                substr($rawContent, 0, 200),
            ));
        }

        $this->assertRequiredKeys($decoded, $schemaJson);
        return $decoded;
    }

    private function stripWrappers(string $content): string
    {
        $content = trim($content);

        if (preg_match('/^```(?:json)?\s*\n(.*)\n```\s*$/s', $content, $m) === 1) {
            return trim($m[1]);
        }

        // Trim prose around a single JSON object: keep what is between the
        // first `{` and the last `}`. Models occasionally prepend "Here is..."
        // and we still want to recover the payload.
        $start = strpos($content, '{');
        $end = strrpos($content, '}');
        if ($start !== false && $end !== false && $end > $start) {
            return substr($content, $start, $end - $start + 1);
        }
        return $content;
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function assertRequiredKeys(array $decoded, string $schemaJson): void
    {
        $schema = json_decode($schemaJson, true);
        if (!is_array($schema) || !isset($schema['required']) || !is_array($schema['required'])) {
            return;
        }
        foreach ($schema['required'] as $requiredKey) {
            if (!is_string($requiredKey)) {
                continue;
            }
            if (!array_key_exists($requiredKey, $decoded)) {
                throw new RuntimeException(sprintf(
                    'LLM response missing required field "%s". Got keys: [%s]',
                    $requiredKey,
                    implode(', ', array_keys($decoded)),
                ));
            }
        }
    }
}

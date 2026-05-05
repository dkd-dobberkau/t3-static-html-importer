<?php

declare(strict_types=1);

namespace T3x\StaticHtmlImporter\Domain\Model;

/**
 * Result of an AiClassifier asset enrichment call.
 *
 * Used to populate `sys_file_metadata` on import. `tags` is free-form, the
 * importer maps them onto categories or keywords as configured.
 */
final readonly class AssetMetadata
{
    /**
     * @param list<string> $tags
     */
    public function __construct(
        public string $altText,
        public string $title,
        public ?string $description = null,
        public array $tags = [],
    ) {
    }
}

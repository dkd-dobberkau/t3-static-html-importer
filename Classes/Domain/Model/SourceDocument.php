<?php

declare(strict_types=1);

namespace T3x\StaticHtmlImporter\Domain\Model;

/**
 * One unit of HTML content from any source adapter.
 *
 * `path` is a logical identifier (filesystem path, URL, pattern id) that the
 * adapter chooses. The importer treats it as opaque except for deduplication.
 */
final readonly class SourceDocument
{
    /**
     * @param array<string, mixed> $metadata adapter-specific data (e.g. crawl depth, source URL, mtime)
     */
    public function __construct(
        public string $path,
        public string $html,
        public array $metadata = [],
    ) {
    }
}

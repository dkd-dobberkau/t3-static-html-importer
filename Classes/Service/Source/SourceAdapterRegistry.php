<?php

declare(strict_types=1);

namespace T3x\StaticHtmlImporter\Service\Source;

/**
 * Picks the right SourceAdapterInterface based on the source string so the
 * commands stay agnostic of where HTML comes from.
 *
 * Routing:
 *   - `http://` / `https://` -> CrawlAdapter
 *   - `pattern-library:<path>` -> PatternLibraryAdapter (prefix stripped)
 *   - everything else -> LocalFilesAdapter
 */
final class SourceAdapterRegistry
{
    private const PATTERN_PREFIX = 'pattern-library:';

    public function __construct(
        private readonly LocalFilesAdapter $local,
        private readonly CrawlAdapter $crawl,
        private readonly PatternLibraryAdapter $pattern,
    ) {
    }

    /**
     * @return array{adapter: SourceAdapterInterface, source: string, name: string}
     */
    public function resolve(string $source): array
    {
        $trimmed = trim($source);

        if (preg_match('#^https?://#i', $trimmed) === 1) {
            return ['adapter' => $this->crawl, 'source' => $trimmed, 'name' => 'crawl'];
        }
        if (str_starts_with($trimmed, self::PATTERN_PREFIX)) {
            return [
                'adapter' => $this->pattern,
                'source' => substr($trimmed, strlen(self::PATTERN_PREFIX)),
                'name' => 'pattern-library',
            ];
        }
        return ['adapter' => $this->local, 'source' => $source, 'name' => 'local'];
    }
}

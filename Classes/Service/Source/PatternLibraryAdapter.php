<?php

declare(strict_types=1);

namespace T3x\StaticHtmlImporter\Service\Source;

use RuntimeException;
use T3x\StaticHtmlImporter\Domain\Model\SourceDocument;

/**
 * Reads HTML from a pattern library export (Storybook, Fractal, ...).
 *
 * @todo Discover Storybook story.json or Fractal manifests, render component
 *       variants to HTML and pass component name plus variant through
 *       SourceDocument::metadata so the analyzer can use them as priors.
 */
final class PatternLibraryAdapter implements SourceAdapterInterface
{
    /**
     * @return iterable<SourceDocument>
     */
    public function read(string $source): iterable
    {
        throw new RuntimeException(
            'PatternLibraryAdapter is a stub (see issue #3). Implement Storybook/Fractal support.',
        );
    }
}

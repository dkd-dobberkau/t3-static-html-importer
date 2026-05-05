<?php

declare(strict_types=1);

namespace T3x\StaticHtmlImporter\Service\Source;

use RuntimeException;
use T3x\StaticHtmlImporter\Domain\Model\SourceDocument;

/**
 * Reads HTML by crawling a remote site.
 *
 * @todo Implement using Symfony BrowserKit + HttpClient. Should respect
 *       robots.txt, support a depth limit and an URL allow-list, and dedupe
 *       URLs across redirects.
 */
final class CrawlAdapter implements SourceAdapterInterface
{
    /**
     * @return iterable<SourceDocument>
     */
    public function read(string $source): iterable
    {
        throw new RuntimeException(
            'CrawlAdapter is a stub (see issue #3). Implement with Symfony BrowserKit.',
        );
    }
}
